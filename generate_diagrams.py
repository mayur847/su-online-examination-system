import os
from PIL import Image, ImageDraw, ImageFont

# 1. Colors & Design Palette
COLOR_BG = (255, 255, 255)
COLOR_TEXT = (31, 41, 55)
COLOR_PRIMARY = (30, 58, 138)       # Navy Blue
COLOR_SECONDARY = (234, 88, 12)     # Saffron
COLOR_LIGHT_BOX = (239, 246, 255)   # Light Blue
COLOR_LIGHT_SAFFRON = (255, 247, 237) # Light Saffron
COLOR_BORDER = (191, 219, 254)      # Soft Blue Border
COLOR_BORDER_SAFFRON = (254, 215, 170) # Soft Saffron Border
COLOR_LINE = (75, 85, 99)

# 2. Font Loader Helper
def get_font(size):
    try:
        # Standard Windows font
        return ImageFont.truetype("C:\\Windows\\Fonts\\arial.ttf", size)
    except IOError:
        try:
            return ImageFont.truetype("C:\\Windows\\Fonts\\calibri.ttf", size)
        except IOError:
            return ImageFont.load_default()

font_title = get_font(16)
font_body = get_font(12)
font_small = get_font(10)

# 3. Drawing Helper Functions
def draw_box(draw, rect, text, is_saffron=False, subtitle=""):
    x1, y1, x2, y2 = rect
    bg = COLOR_LIGHT_SAFFRON if is_saffron else COLOR_LIGHT_BOX
    border = COLOR_BORDER_SAFFRON if is_saffron else COLOR_BORDER
    primary = COLOR_SECONDARY if is_saffron else COLOR_PRIMARY
    
    # Draw rounded rectangle
    draw.rounded_rectangle([x1, y1, x2, y2], radius=8, fill=bg, outline=border, width=2)
    
    # Write text centered
    w = x2 - x1
    h = y2 - y1
    
    # Estimate text height and width
    lines = []
    if text:
        lines.extend(text.split('\n'))
    if subtitle:
        lines.extend(subtitle.split('\n'))
        
    y_offset = y1 + (h - len(lines) * 16) / 2
    for line in lines:
        try:
            tw = draw.textlength(line, font=font_body)
        except Exception:
            tw = len(line) * 6 # fallback estimate
        draw.text((x1 + (w - tw) / 2, y_offset), line, fill=COLOR_TEXT, font=font_body)
        y_offset += 16

def draw_arrow(draw, start, end):
    x1, y1 = start
    x2, y2 = end
    
    # Draw line
    draw.line([x1, y1, x2, y2], fill=COLOR_LINE, width=2)
    
    # Draw arrowhead pointing to end
    arrow_size = 8
    import math
    dx = x2 - x1
    dy = y2 - y1
    angle = math.atan2(dy, dx)
    
    # Calculate arrowhead points
    ax1 = x2 - arrow_size * math.cos(angle - math.pi / 6)
    ay1 = y2 - arrow_size * math.sin(angle - math.pi / 6)
    ax2 = x2 - arrow_size * math.cos(angle + math.pi / 6)
    ay2 = y2 - arrow_size * math.sin(angle + math.pi / 6)
    
    draw.polygon([x2, y2, ax1, ay1, ax2, ay2], fill=COLOR_LINE)

def draw_database(draw, rect, name):
    x1, y1, x2, y2 = rect
    # Draw database cylinder
    w = x2 - x1
    h = y2 - y1
    
    # Draw top ellipse
    draw.ellipse([x1, y1, x2, y1 + 20], fill=COLOR_LIGHT_BOX, outline=COLOR_PRIMARY, width=2)
    # Draw bottom ellipse
    draw.ellipse([x1, y2 - 20, x2, y2], fill=COLOR_LIGHT_BOX, outline=COLOR_PRIMARY, width=2)
    # Draw side lines
    draw.line([x1, y1 + 10, x1, y2 - 10], fill=COLOR_PRIMARY, width=2)
    draw.line([x2, y1 + 10, x2, y2 - 10], fill=COLOR_PRIMARY, width=2)
    # Fill middle body
    draw.rectangle([x1 + 1, y1 + 10, x2 - 1, y2 - 10], fill=COLOR_LIGHT_BOX)
    
    # Draw lines to give 3D cylinder look
    draw.arc([x1, y1 + 10, x2, y1 + 30], start=0, end=180, fill=COLOR_PRIMARY, width=2)
    draw.arc([x1, y1 + 25, x2, y1 + 45], start=0, end=180, fill=COLOR_PRIMARY, width=2)
    
    # Write Database Name line-by-line
    lines = name.split('\n')
    y_offset = y1 + h/2 - (len(lines) * 14) / 2
    for line in lines:
        try:
            tw = draw.textlength(line, font=font_body)
        except Exception:
            tw = len(line) * 6
        draw.text((x1 + (w - tw) / 2, y_offset), line, fill=COLOR_TEXT, font=font_body)
        y_offset += 14

def draw_actor(draw, pos, name):
    x, y = pos
    # Head
    draw.ellipse([x - 10, y - 30, x + 10, y - 10], fill=COLOR_LIGHT_BOX, outline=COLOR_PRIMARY, width=2)
    # Body/Torso
    draw.line([x, y - 10, x, y + 15], fill=COLOR_PRIMARY, width=2)
    # Arms
    draw.line([x - 20, y, x + 20, y], fill=COLOR_PRIMARY, width=2)
    # Legs
    draw.line([x, y + 15, x - 15, y + 35], fill=COLOR_PRIMARY, width=2)
    draw.line([x, y + 15, x + 15, y + 35], fill=COLOR_PRIMARY, width=2)
    
    # Name
    try:
        tw = draw.textlength(name, font=font_body)
    except AttributeError:
        tw = len(name) * 6
    draw.text((x - tw/2, y + 40), name, fill=COLOR_TEXT, font=font_body)

def draw_usecase(draw, rect, text):
    x1, y1, x2, y2 = rect
    draw.ellipse([x1, y1, x2, y2], fill=COLOR_LIGHT_SAFFRON, outline=COLOR_SECONDARY, width=2)
    w = x2 - x1
    h = y2 - y1
    try:
        tw = draw.textlength(text, font=font_body)
    except AttributeError:
        tw = len(text) * 6
    draw.text((x1 + (w - tw) / 2, y1 + (h - 12) / 2), text, fill=COLOR_TEXT, font=font_body)

def draw_diamond(draw, center, size, text):
    cx, cy = center
    half = size / 2
    points = [
        (cx, cy - half), # Top
        (cx + half, cy), # Right
        (cx, cy + half), # Bottom
        (cx - half, cy)  # Left
    ]
    draw.polygon(points, fill=COLOR_LIGHT_SAFFRON, outline=COLOR_SECONDARY, width=2)
    try:
        tw = draw.textlength(text, font=font_body)
    except AttributeError:
        tw = len(text) * 6
    draw.text((cx - tw/2, cy - 6), text, fill=COLOR_TEXT, font=font_body)

# 4. Generate System Architecture
def make_system_architecture():
    img = Image.new("RGB", (800, 400), COLOR_BG)
    draw = ImageDraw.Draw(img)
    
    # Draw Title
    draw.text((20, 20), "SYSTEM ARCHITECTURE (THREE-TIER APPLICATION)", fill=COLOR_PRIMARY, font=font_title)
    
    # Components
    draw_box(draw, (50, 150, 200, 250), "Client Browser", is_saffron=True, subtitle="(HTML5, CSS, JS)")
    draw_box(draw, (300, 150, 480, 250), "PHP Web Application", is_saffron=False, subtitle="WebServer (Apache/Nginx)")
    draw_box(draw, (580, 70, 750, 170), "Python AI Grader", is_saffron=False, subtitle="Auto-Grading Engine")
    draw_database(draw, (580, 220, 750, 360), "MySQL Database\n(su_exam_db)")
    
    # Connections
    draw_arrow(draw, (200, 180), (300, 180)) # Client to PHP
    draw_arrow(draw, (300, 220), (200, 220)) # PHP to Client
    
    draw_arrow(draw, (480, 170), (580, 130)) # PHP exec Python Grader
    draw_arrow(draw, (580, 110), (480, 150)) # Grader completed callback
    
    draw_arrow(draw, (450, 250), (580, 270)) # PHP queries MySQL
    draw_arrow(draw, (580, 290), (450, 250)) # DB results to PHP
    
    draw_arrow(draw, (665, 170), (665, 220)) # Python Grader writes/reads DB
    draw_arrow(draw, (665, 220), (665, 170))
    
    # Text labels
    draw.text((215, 155), "HTTP(S) Request", fill=COLOR_TEXT, font=font_small)
    draw.text((215, 225), "HTML/JSON Response", fill=COLOR_TEXT, font=font_small)
    draw.text((495, 115), "shell_exec()", fill=COLOR_TEXT, font=font_small)
    draw.text((495, 235), "PDO Connection", fill=COLOR_TEXT, font=font_small)
    
    img.save("system_architecture.png")

# 5. Generate Activity Diagram
def make_activity_diagram():
    img = Image.new("RGB", (800, 500), COLOR_BG)
    draw = ImageDraw.Draw(img)
    
    draw.text((20, 20), "STUDENT ASSESSMENT & PROCTORING ACTIVITY FLOW", fill=COLOR_PRIMARY, font=font_title)
    
    # Start State
    draw.ellipse([40, 100, 60, 120], fill=COLOR_PRIMARY)
    draw.text((35, 75), "Start", fill=COLOR_TEXT, font=font_body)
    
    # Steps
    draw_box(draw, (100, 85, 220, 135), "Student Log In", is_saffron=True)
    draw_box(draw, (260, 85, 380, 135), "Select Active Exam", is_saffron=True)
    draw_box(draw, (420, 85, 560, 135), "Render Exam UI", is_saffron=True, subtitle="(Timer & Trackers init)")
    
    # Connect Start to first boxes
    draw_arrow(draw, (60, 110), (100, 110))
    draw_arrow(draw, (220, 110), (260, 110))
    draw_arrow(draw, (380, 110), (420, 110))
    
    # Exam Cycle Box
    draw_box(draw, (420, 200, 560, 270), "Attempt Questions", is_saffron=True, subtitle="(MCQ & Descriptive)")
    draw_arrow(draw, (490, 135), (490, 200))
    
    # Decision Diamond (Tab focus/Blur or cheat)
    draw_diamond(draw, (240, 235), 90, "Tab Out?")
    draw_arrow(draw, (420, 235), (285, 235))
    
    # If Tab Out (Yes)
    draw_box(draw, (40, 185, 170, 235), "Log Violation\n& Warn Student", is_saffron=False)
    draw_arrow(draw, (240, 190), (105, 190))
    draw.text((180, 170), "Yes", fill=COLOR_TEXT, font=font_body)
    
    # Connect Violation back to attempt
    draw_arrow(draw, (105, 235), (240, 280))
    draw_arrow(draw, (240, 280), (420, 255))
    
    # Decision Diamond (Switches >= 5?)
    draw_diamond(draw, (105, 330), 80, "Count >= 5?")
    draw_arrow(draw, (105, 235), (105, 290))
    
    # Auto Submit
    draw_box(draw, (250, 305, 380, 355), "Force Auto-Submit", is_saffron=False)
    draw_arrow(draw, (145, 330), (250, 330))
    draw.text((180, 310), "Yes (Lock)", fill=COLOR_TEXT, font=font_body)
    
    # Connect Count < 5 back to attempt
    draw_arrow(draw, (105, 370), (490, 370))
    draw_arrow(draw, (490, 370), (490, 270))
    draw.text((115, 380), "No", fill=COLOR_TEXT, font=font_body)
    
    # Manual Submit
    draw_box(draw, (620, 200, 750, 270), "Manual Submit", is_saffron=True)
    draw_arrow(draw, (560, 235), (620, 235))
    
    # Combine Submissions to Python Grader
    draw_box(draw, (420, 400, 560, 450), "Python AI Auto Grader", is_saffron=False, subtitle="(String Match & NLP)")
    
    # Arrow from Force Auto-Submit to Grader
    draw_arrow(draw, (315, 355), (420, 410))
    # Arrow from Manual Submit to Grader
    draw_arrow(draw, (685, 270), (560, 410))
    
    # End state
    draw.ellipse([640, 415, 660, 435], fill=COLOR_PRIMARY)
    draw.ellipse([637, 412, 663, 438], outline=COLOR_PRIMARY, width=2)
    draw_arrow(draw, (560, 425), (637, 425))
    draw.text((635, 445), "End", fill=COLOR_TEXT, font=font_body)
    
    img.save("activity_diagram.png")

# 6. Generate Use Case Diagram
def make_use_case_diagram():
    img = Image.new("RGB", (800, 500), COLOR_BG)
    draw = ImageDraw.Draw(img)
    
    draw.text((20, 20), "USE CASE MODEL - EXAM SYSTEM INTERACTION", fill=COLOR_PRIMARY, font=font_title)
    
    # System boundary box
    draw.rectangle([150, 60, 650, 480], outline=COLOR_PRIMARY, width=2)
    draw.text((160, 70), "Online Examination System Boundary", fill=COLOR_PRIMARY, font=font_small)
    
    # Actors
    draw_actor(draw, (60, 230), "Student (User)")
    draw_actor(draw, (730, 230), "Admin (Proctor)")
    
    # Use cases (Student)
    draw_usecase(draw, (200, 90, 360, 140), "Register / Login")
    draw_usecase(draw, (200, 160, 360, 210), "View Dashboard & Results")
    draw_usecase(draw, (200, 230, 360, 280), "Attempt Online Exam")
    draw_usecase(draw, (200, 300, 360, 350), "Auto-save Draft Answers")
    
    # Use cases (Shared / Admin)
    draw_usecase(draw, (440, 130, 600, 180), "Manage Exams & Questions")
    draw_usecase(draw, (440, 210, 600, 260), "Real-time Proctor Monitor")
    draw_usecase(draw, (440, 290, 600, 340), "Send Proctor Warning")
    draw_usecase(draw, (440, 370, 600, 420), "Execute Python Grader")
    
    # Draw connections for Student
    draw.line([90, 210, 200, 115], fill=COLOR_LINE, width=1)
    draw.line([90, 210, 200, 185], fill=COLOR_LINE, width=1)
    draw.line([90, 210, 200, 255], fill=COLOR_LINE, width=1)
    draw.line([90, 210, 200, 325], fill=COLOR_LINE, width=1)
    
    # Draw connections for Admin
    draw.line([700, 210, 600, 155], fill=COLOR_LINE, width=1)
    draw.line([700, 210, 600, 235], fill=COLOR_LINE, width=1)
    draw.line([700, 210, 600, 315], fill=COLOR_LINE, width=1)
    draw.line([700, 210, 600, 395], fill=COLOR_LINE, width=1)
    
    # Admin also manages login
    draw.line([700, 210, 360, 115], fill=COLOR_LINE, width=1)
    
    img.save("use_case_diagram.png")

# 7. Generate Data Flow Diagram (DFD Level 1)
def make_dfd_diagram():
    img = Image.new("RGB", (800, 500), COLOR_BG)
    draw = ImageDraw.Draw(img)
    
    draw.text((20, 20), "DATA FLOW DIAGRAM (DFD LEVEL 1) - EXAM SYSTEM PROCESSES", fill=COLOR_PRIMARY, font=font_title)
    
    # External Entities
    draw_box(draw, (20, 180, 120, 250), "Student", is_saffron=True)
    draw_box(draw, (680, 180, 780, 250), "Admin", is_saffron=True)
    
    # Processes
    draw_box(draw, (220, 70, 350, 130), "1.0\nAuthentication", is_saffron=False)
    draw_box(draw, (220, 180, 350, 240), "2.0\nTake Exam & Proctor", is_saffron=False)
    draw_box(draw, (450, 180, 580, 240), "3.0\nManage Exams", is_saffron=False)
    draw_box(draw, (320, 380, 480, 440), "4.0\nAI auto-grading engine", is_saffron=False)
    
    # Data stores (Lines top/bottom)
    def draw_datastore(draw, rect, id_str, name):
        x1, y1, x2, y2 = rect
        draw.line([x1, y1, x2, y1], fill=COLOR_PRIMARY, width=2)
        draw.line([x1, y2, x2, y2], fill=COLOR_PRIMARY, width=2)
        draw.rectangle([x1, y1+1, x2, y2-1], fill=COLOR_LIGHT_BOX, outline=(0,0,0,0))
        draw.text((x1 + 10, y1 + 5), f"{id_str}: {name}", fill=COLOR_TEXT, font=font_body)
        
    draw_datastore(draw, (20, 300, 150, 340), "D1", "students")
    draw_datastore(draw, (480, 70, 620, 110), "D2", "exams & questions")
    draw_datastore(draw, (200, 300, 360, 340), "D3", "student_exams")
    draw_datastore(draw, (440, 300, 600, 340), "D4", "student_answers")
    
    # Flows
    # Student Auth
    draw_arrow(draw, (90, 180), (220, 100)) # credentials
    draw_arrow(draw, (220, 120), (70, 180)) # auth token
    
    # Admin Auth
    draw_arrow(draw, (710, 180), (350, 100)) # credentials
    
    # Process 1.0 writes/reads D1
    draw_arrow(draw, (220, 95), (150, 305))
    
    # Student take exam
    draw_arrow(draw, (120, 210), (220, 210)) # answers, tab activity
    draw_arrow(draw, (220, 230), (120, 230)) # question text, warnings
    
    # Process 2.0 reads D2
    draw_arrow(draw, (480, 90), (330, 180))
    
    # Process 2.0 writes D3 & D4
    draw_arrow(draw, (280, 240), (280, 300)) # write exam state
    draw_arrow(draw, (330, 240), (440, 305)) # write student answers
    
    # Admin manage exams
    draw_arrow(draw, (680, 210), (580, 210)) # exam data, questions
    draw_arrow(draw, (530, 180), (530, 110)) # write D2 (exams & questions)
    
    # Python Grader (Process 4.0) reads D2 (model answer), reads D4 (student answer), writes D3 (score), writes D4 (marks/feedback)
    draw_arrow(draw, (400, 380), (280, 340)) # write D3 score
    draw_arrow(draw, (420, 380), (480, 340)) # write D4 marks
    draw_arrow(draw, (380, 340), (380, 380)) # read D3 details
    
    # Admin view live proctor
    draw_arrow(draw, (350, 220), (680, 225)) # live status
    
    img.save("dfd_diagram.png")

# 8. Generate Entity-Relationship (E-R) Diagram
def make_er_diagram():
    img = Image.new("RGB", (800, 500), COLOR_BG)
    draw = ImageDraw.Draw(img)
    
    draw.text((20, 20), "ENTITY-RELATIONSHIP (E-R) DIAGRAM - DATABASE MODEL", fill=COLOR_PRIMARY, font=font_title)
    
    # Entity Boxes
    draw_box(draw, (50, 100, 170, 150), "Admins", is_saffron=False)
    draw_box(draw, (50, 350, 170, 400), "Students", is_saffron=False)
    draw_box(draw, (320, 100, 440, 150), "Exams", is_saffron=False)
    draw_box(draw, (600, 100, 720, 150), "Questions", is_saffron=False)
    draw_box(draw, (320, 350, 450, 400), "Student Exams", is_saffron=False)
    draw_box(draw, (600, 350, 730, 400), "Student Answers", is_saffron=False)
    
    # Relationship Diamonds
    def draw_rel_diamond(draw, center, text):
        cx, cy = center
        draw_diamond(draw, (cx, cy), 60, text)
        
    draw_rel_diamond(draw, (245, 125), "Creates")
    draw_rel_diamond(draw, (520, 125), "Contains")
    draw_rel_diamond(draw, (245, 375), "Takes")
    draw_rel_diamond(draw, (525, 375), "Details")
    
    # Connecting Lines & Cardinalities
    # Admins Create Exams (1 to N)
    draw.line([170, 125, 215, 125], fill=COLOR_LINE, width=2)
    draw.line([275, 125, 320, 125], fill=COLOR_LINE, width=2)
    draw.text((180, 105), "1", fill=COLOR_PRIMARY, font=font_body)
    draw.text((300, 105), "N", fill=COLOR_PRIMARY, font=font_body)
    
    # Exams Contain Questions (1 to N)
    draw.line([440, 125, 490, 125], fill=COLOR_LINE, width=2)
    draw.line([550, 125, 600, 125], fill=COLOR_LINE, width=2)
    draw.text((450, 105), "1", fill=COLOR_PRIMARY, font=font_body)
    draw.text((580, 105), "N", fill=COLOR_PRIMARY, font=font_body)
    
    # Students Take Exams (1 to N via Student Exams)
    draw.line([170, 375, 215, 375], fill=COLOR_LINE, width=2)
    draw.line([275, 375, 320, 375], fill=COLOR_LINE, width=2)
    draw.text((180, 355), "1", fill=COLOR_PRIMARY, font=font_body)
    draw.text((300, 355), "N", fill=COLOR_PRIMARY, font=font_body)
    
    # Exam has Student Exams (1 to N)
    draw.line([380, 150, 380, 350], fill=COLOR_LINE, width=2)
    draw_rel_diamond(draw, (380, 250), "Has")
    draw.text((390, 160), "1", fill=COLOR_PRIMARY, font=font_body)
    draw.text((390, 330), "N", fill=COLOR_PRIMARY, font=font_body)
    
    # Student Exam Details Answers (1 to N)
    draw.line([450, 375, 495, 375], fill=COLOR_LINE, width=2)
    draw.line([555, 375, 600, 375], fill=COLOR_LINE, width=2)
    draw.text((460, 355), "1", fill=COLOR_PRIMARY, font=font_body)
    draw.text((580, 355), "N", fill=COLOR_PRIMARY, font=font_body)
    
    # Question details Student Answers (1 to N)
    draw.line([660, 150, 660, 350], fill=COLOR_LINE, width=2)
    draw_rel_diamond(draw, (660, 250), "Records")
    draw.text((670, 160), "1", fill=COLOR_PRIMARY, font=font_body)
    draw.text((670, 330), "N", fill=COLOR_PRIMARY, font=font_body)
    
    img.save("er_diagram.png")

# 9. Main function
if __name__ == '__main__':
    print("Drawing diagrams...")
    make_system_architecture()
    make_activity_diagram()
    make_use_case_diagram()
    make_dfd_diagram()
    make_er_diagram()
    print("All diagrams drawn and saved successfully as PNGs.")
