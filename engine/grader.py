import sys
import subprocess
import re

# 1. Programmatically ensure mysql-connector-python is installed
try:
    import mysql.connector
except ImportError:
    print("mysql-connector-python not found. Installing via pip...")
    try:
        subprocess.check_call([sys.executable, "-m", "pip", "install", "mysql-connector-python"])
        import mysql.connector
        print("mysql-connector-python successfully installed.")
    except Exception as e:
        print(f"Failed to install mysql-connector-python: {e}")
        sys.exit(1)

from difflib import SequenceMatcher

def clean_text(text):
    if not text:
        return ""
    # Remove special characters, lowercase
    text = text.lower().strip()
    text = re.sub(r'[^\w\s]', '', text)
    return text

def calculate_keyword_score(student_ans, model_ans):
    # Standard filler words to ignore
    stopwords = {'the', 'a', 'an', 'and', 'but', 'is', 'are', 'was', 'were', 'it', 'they', 'we', 'you', 'he', 'she', 'of', 'in', 'to', 'for', 'with', 'on', 'at', 'by', 'an', 'or', 'as', 'that', 'this'}
    
    # Extract keywords from model answer (words of length >= 4 that are not stopwords)
    model_words = set(w for w in clean_text(model_ans).split() if len(w) >= 4 and w not in stopwords)
    if not model_words:
        return 1.0
        
    student_words = set(clean_text(student_ans).split())
    matched_words = model_words.intersection(student_words)
    
    match_ratio = len(matched_words) / len(model_words)
    return match_ratio, list(matched_words)

def grade_exam(student_exam_id):
    db_config = {
        'host': 'localhost',
        'database': 'su_exam_db',
        'user': 'root',
        'password': ''
    }
    
    conn = None
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor(dictionary=True)
        
        # 1. Fetch Student Exam and Total Possible Score
        cursor.execute("SELECT * FROM student_exams WHERE id = %s", (student_exam_id,))
        student_exam = cursor.fetchone()
        if not student_exam:
            print(f"Error: Student exam record {student_exam_id} not found.")
            return
            
        exam_id = student_exam['exam_id']
        
        # Fetch questions for this exam
        cursor.execute("SELECT * FROM questions WHERE exam_id = %s", (exam_id,))
        questions = {q['id']: q for q in cursor.fetchall()}
        
        # Fetch student answers
        cursor.execute("SELECT * FROM student_answers WHERE student_exam_id = %s", (student_exam_id,))
        student_answers = cursor.fetchall()
        
        total_obtained_score = 0.0
        total_possible_score = 0
        
        # Build list of active questions to calculate total possible points
        for q_id, q in questions.items():
            total_possible_score += q['points']
            
        # Map student answers by question ID
        student_ans_map = {sa['question_id']: sa for sa in student_answers}
        
        # Grade each question
        for q_id, q in questions.items():
            points = q['points']
            
            # If student didn't answer, score is 0
            if q_id not in student_ans_map:
                # Insert empty answer row
                cursor.execute(
                    "INSERT INTO student_answers (student_exam_id, question_id, student_answer, marks_obtained, auto_feedback) VALUES (%s, %s, '', 0.0, 'No answer submitted.')",
                    (student_exam_id, q_id)
                )
                continue
                
            sa = student_ans_map[q_id]
            student_text = sa['student_answer'].strip()
            
            if q['type'] == 'mcq':
                correct_opt = q['correct_option'].strip().upper()
                student_opt = student_text.upper()
                
                if student_opt == correct_opt:
                    marks = float(points)
                    feedback = "Correct Answer."
                else:
                    marks = 0.0
                    feedback = f"Incorrect. Correct option is ({correct_opt})."
            else:
                # Descriptive answer grading
                model_ans = q['model_answer']
                
                if not student_text:
                    marks = 0.0
                    feedback = "Answer is blank."
                else:
                    # Sequence similarity (60% weight)
                    seq_ratio = SequenceMatcher(None, clean_text(student_text), clean_text(model_ans)).ratio()
                    
                    # Keyword overlap (40% weight)
                    keyword_ratio, matched_keywords = calculate_keyword_score(student_text, model_ans)
                    
                    # Total weighted ratio
                    weighted_ratio = (seq_ratio * 0.5) + (keyword_ratio * 0.5)
                    
                    # Scaling grade (students shouldn't need a 100% exact copy of model answer for full marks)
                    # If similarity is >= 80%, give full marks. If >= 40%, scale it. Else give minimal marks.
                    if weighted_ratio >= 0.75:
                        grade_factor = 1.0
                    elif weighted_ratio >= 0.30:
                        grade_factor = weighted_ratio / 0.75
                    else:
                        grade_factor = weighted_ratio * 0.5
                        
                    marks = round(min(grade_factor, 1.0) * float(points), 2)
                    
                    matched_str = ", ".join(matched_keywords[:6]) if matched_keywords else "None"
                    feedback = f"Auto-graded (Similarity: {int(weighted_ratio*100)}%). Matches: [{matched_str}]"
            
            # Update student answer table
            cursor.execute(
                "UPDATE student_answers SET marks_obtained = %s, auto_feedback = %s WHERE id = %s",
                (marks, feedback, sa['id'])
            )
            total_obtained_score += marks
            
        # Update student exam status and score
        cursor.execute(
            "UPDATE student_exams SET score = %s, total_possible_score = %s, status = 'graded' WHERE id = %s",
            (total_obtained_score, total_possible_score, student_exam_id)
        )
        
        conn.commit()
        print(f"Grading Completed Successfully. Total Score: {total_obtained_score}/{total_possible_score}")
        
    except Exception as e:
        print(f"Database/Grading Error: {e}")
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python grader.py <student_exam_id>")
        sys.exit(1)
        
    grade_exam(int(sys.argv[1]))
