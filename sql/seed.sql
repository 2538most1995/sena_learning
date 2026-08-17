USE sena_learning;

INSERT INTO courses (title, description, category, cover_url, pass_percent, certificate_title)
VALUES (
    'หลักสูตรตัวอย่าง: การใช้เทคโนโลยีเพื่อการเรียนรู้',
    'เรียนรู้แนวคิดพื้นฐานของการใช้สื่อดิจิทัล การวัดผล และการออกแบบกิจกรรมการเรียนรู้ที่เหมาะกับผู้เรียนทุกอุปกรณ์',
    'lifelong',
    '',
    80,
    'เกียรติบัตรผ่านการเรียนรู้ SENA Learning'
);

SET @course_id = LAST_INSERT_ID();

INSERT INTO lessons (course_id, title, content_type, content, sort_order) VALUES
(@course_id, 'บทที่ 1 ภาพรวมการเรียนรู้แบบเปิด', 'html',
'<h2>การเรียนรู้สำหรับสมาชิกคืออะไร</h2><p>ผู้เรียนเข้าสู่ระบบก่อนเริ่มเรียน ระบบจะบันทึกความคืบหน้าและใช้ชื่อจากบัญชีเพื่อออกเกียรติบัตรเมื่อผ่านเกณฑ์</p><ul><li>บันทึกประวัติรายบุคคล</li><li>วัดผลก่อนและหลังเรียน</li><li>ออกหลักฐานการเรียนรู้อัตโนมัติ</li></ul>', 1),
(@course_id, 'บทที่ 2 การจัดสื่อและแบบทดสอบ', 'html',
'<h2>ออกแบบบทเรียนให้ยืดหยุ่น</h2><p>ผู้ดูแลสามารถเพิ่มบทเรียนแบบข้อความ วิดีโอ ลิงก์ หรือ embed สื่อภายนอก แล้วนำเข้าข้อสอบได้หลายรูปแบบ</p>', 2);

INSERT INTO questions (course_id, quiz_type, question_type, prompt, choices, correct_answers, explanation, sort_order) VALUES
(@course_id, 'pre', 'single_choice', 'ระบบนี้ต้องเข้าสู่ระบบก่อนเรียนหรือไม่', JSON_ARRAY('ต้องเข้าสู่ระบบ', 'ไม่ต้องเข้าสู่ระบบ', 'ต้องรออนุมัติ'), JSON_ARRAY('ต้องเข้าสู่ระบบ'), 'ระบบบันทึกความคืบหน้าแยกตามบัญชีผู้เรียน', 1),
(@course_id, 'pre', 'true_false', 'แบบทดสอบก่อนเรียนช่วยประเมินความรู้ตั้งต้นของผู้เรียน', JSON_ARRAY('ถูก', 'ผิด'), JSON_ARRAY('ถูก'), '', 2),
(@course_id, 'post', 'single_choice', 'เงื่อนไขสำคัญในการออกเกียรติบัตรคือข้อใด', JSON_ARRAY('กรอกชื่ออย่างเดียว', 'ผ่านแบบทดสอบหลังเรียนตามเกณฑ์', 'เปิดหน้าแรกครบ 1 ครั้ง'), JSON_ARRAY('ผ่านแบบทดสอบหลังเรียนตามเกณฑ์'), '', 1),
(@course_id, 'post', 'multiple_choice', 'องค์ประกอบใดควรมีในระบบจัดการเรียนรู้', JSON_ARRAY('บทเรียน/สื่อ', 'แบบทดสอบก่อนเรียน', 'แบบทดสอบหลังเรียน', 'ปิดไม่ให้ผู้เรียนทั่วไปเข้า'), JSON_ARRAY('บทเรียน/สื่อ', 'แบบทดสอบก่อนเรียน', 'แบบทดสอบหลังเรียน'), '', 2),
(@course_id, 'post', 'short_answer', 'พิมพ์คำว่า SENA เพื่อยืนยันการเรียนรู้', JSON_ARRAY(), JSON_ARRAY('SENA', 'sena'), '', 3);

INSERT INTO courses (title, description, category, cover_url, pass_percent, certificate_title)
VALUES
('การพัฒนาที่ยั่งยืนในชีวิตประจำวัน', 'เรียนรู้แนวคิดการพัฒนาที่ยั่งยืนและวิธีการนำไปใช้ในชีวิตประจำวัน', 'lifelong', '', 80, 'เกียรติบัตรผ่านการเรียนรู้ SENA Learning'),
('ความปลอดภัยไซเบอร์สำหรับทุกคน', 'เสริมสร้างความรู้และทักษะเพื่อใช้งานอินเทอร์เน็ตอย่างปลอดภัย', 'self_development', '', 80, 'เกียรติบัตรผ่านการเรียนรู้ SENA Learning'),
('การเงินส่วนบุคคลอย่างชาญฉลาด', 'วางแผนการเงิน จัดการรายรับรายจ่าย และสร้างความมั่นคงทางการเงิน', 'self_development', '', 80, 'เกียรติบัตรผ่านการเรียนรู้ SENA Learning'),
('สุขภาพดีเริ่มที่ตัวเรา', 'ดูแลสุขภาพกายและใจให้แข็งแรงในทุกช่วงวัย', 'qualification_level', '', 80, 'เกียรติบัตรผ่านการเรียนรู้ SENA Learning');

INSERT INTO lessons (course_id, title, content_type, content, sort_order)
SELECT id, 'บทเรียนหลัก', 'html', CONCAT('<h2>', title, '</h2><p>', description, '</p><ul><li>เรียนรู้แนวคิดสำคัญ</li><li>ฝึกทบทวนด้วยแบบทดสอบ</li><li>รับเกียรติบัตรเมื่อผ่านเกณฑ์</li></ul>'), 1
FROM courses
WHERE title IN (
    'การพัฒนาที่ยั่งยืนในชีวิตประจำวัน',
    'ความปลอดภัยไซเบอร์สำหรับทุกคน',
    'การเงินส่วนบุคคลอย่างชาญฉลาด',
    'สุขภาพดีเริ่มที่ตัวเรา'
);

INSERT INTO questions (course_id, quiz_type, question_type, prompt, choices, correct_answers, explanation, sort_order)
SELECT id, 'pre', 'single_choice', 'การเรียนรู้ในระบบนี้ต้องเข้าสู่ระบบก่อนหรือไม่', JSON_ARRAY('ต้องเข้าสู่ระบบ', 'ไม่ต้องเข้าสู่ระบบ'), JSON_ARRAY('ต้องเข้าสู่ระบบ'), '', 1
FROM courses
WHERE title IN (
    'การพัฒนาที่ยั่งยืนในชีวิตประจำวัน',
    'ความปลอดภัยไซเบอร์สำหรับทุกคน',
    'การเงินส่วนบุคคลอย่างชาญฉลาด',
    'สุขภาพดีเริ่มที่ตัวเรา'
);

INSERT INTO questions (course_id, quiz_type, question_type, prompt, choices, correct_answers, explanation, sort_order)
SELECT id, 'post', 'single_choice', 'เงื่อนไขในการรับเกียรติบัตรคือข้อใด', JSON_ARRAY('ผ่านแบบทดสอบหลังเรียนตามเกณฑ์', 'เปิดหน้าเว็บอย่างเดียว'), JSON_ARRAY('ผ่านแบบทดสอบหลังเรียนตามเกณฑ์'), '', 1
FROM courses
WHERE title IN (
    'การพัฒนาที่ยั่งยืนในชีวิตประจำวัน',
    'ความปลอดภัยไซเบอร์สำหรับทุกคน',
    'การเงินส่วนบุคคลอย่างชาญฉลาด',
    'สุขภาพดีเริ่มที่ตัวเรา'
);
