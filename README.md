# SENA Learning

เว็บแอปจัดการเรียนรู้แบบสมาชิก สร้างด้วย PHP, MySQL และ Tailwind CSS สำหรับ MAMP

## ความสามารถหลัก

- ประชาชนทั่วไปสมัครด้วยอีเมล หรือเข้าสู่ระบบด้วย Google และ LINE ได้
- ผู้เรียนต้องเข้าสู่ระบบก่อนเริ่มเรียน นักศึกษา ศกร. ใช้เลขบัตรประชาชน 13 หลักเพียงอย่างเดียว
- คลังชุดข้อสอบกลาง เลือกแทรกในลำดับการเรียนได้อิสระ
- บทเรียนรองรับ HTML/ข้อความ, วิดีโอ URL, embed code และลิงก์สื่อ
- ออกเกียรติบัตรอัตโนมัติเมื่อเรียนครบและคะแนนรวมผ่านเกณฑ์
- ดาวน์โหลดเกียรติบัตรเป็น PNG หรือ PDF จาก layout เดียวกับหน้าออกแบบ
- จัดการบัญชี admin หลายบัญชี และตั้งรหัสผ่านใหม่ให้ประชาชนทั่วไปได้
- หลังบ้านแบ่งส่วน ลากเรียงบทเรียน สื่อ และชุดข้อสอบ พร้อมกำหนดรายการที่ต้องเรียนก่อน
- หลังบ้านเพิ่ม/แก้ไขชุดข้อสอบ เฉลย และนำเข้าข้อสอบ JSON / Excel
- ข้อสอบรองรับ `single_choice`, `multiple_choice`, `true_false`, `short_answer`
- Responsive รองรับมือถือ แท็บเล็ต และคอมพิวเตอร์

## การติดตั้งบน MAMP

1. เปิด MAMP ให้ MySQL ทำงาน
2. ตรวจค่าเชื่อมต่อใน `config/config.php`
   - ค่าเริ่มต้น: host `127.0.0.1`, port `8889`, user `root`, password `root`
3. เปิด `http://localhost:8888/sena_learning/install.php`
4. กดสร้างฐานข้อมูลและข้อมูลตัวอย่าง
5. เปิดหน้าเรียนที่ `http://localhost:8888/sena_learning/index.php`

ถ้า Apache ของ MAMP ใช้ document root อื่น ให้ชี้ document root มาที่ `/Applications/MAMP/htdocs` หรือย้ายโฟลเดอร์นี้ไปยัง document root ที่ใช้งานอยู่

## หลังบ้าน

- URL: `http://localhost:8888/sena_learning/admin/index.php`
- username เริ่มต้น: `admin`
- password เริ่มต้น: `123456`
- หลังเข้าสู่ระบบ ให้เพิ่มบัญชี admin หรือตั้งรหัสผ่านใหม่ที่เมนู `จัดการผู้ใช้`
- ค่า `ADMIN_USERNAME` และ `ADMIN_PIN` ใน `config/config.php` ใช้สร้างบัญชี admin เริ่มต้นเฉพาะตอนที่ตารางยังไม่มีข้อมูล

## ตั้งค่า Google และ LINE Login

1. ตั้ง `SENA_LEARNING_APP_URL` เป็น URL จริงของระบบ โดย production ต้องใช้ HTTPS
2. คัดลอก `config/oauth.example.php` เป็น `config/oauth.php` และใส่ Client ID/Secret จริง ไฟล์ `oauth.php` ถูก ignore และห้าม commit
3. Google Cloud Console: เพิ่ม Authorized redirect URI เป็น
   `https://your-domain.example/sena_learning/auth/google_callback.php`
4. LINE Developers Console: สร้าง LINE Login channel, เพิ่ม Callback URL เป็น
   `https://your-domain.example/sena_learning/auth/line_callback.php` และ Publish channel ก่อนเปิดให้ประชาชนใช้
5. หากต้องการรับอีเมลจาก LINE ให้ยื่นขอ Email address permission ใน LINE Developers Console; หากไม่ได้รับสิทธิ์ ระบบยังสมัคร/เข้าสู่ระบบด้วย LINE user ID และชื่อโปรไฟล์ได้

สามารถใช้ environment variables แทนไฟล์ได้:

```text
SENA_LEARNING_GOOGLE_CLIENT_ID=...
SENA_LEARNING_GOOGLE_CLIENT_SECRET=...
SENA_LEARNING_LINE_CHANNEL_ID=...
SENA_LEARNING_LINE_CHANNEL_SECRET=...
```

ปุ่มผู้ให้บริการจะแสดงเฉพาะรายที่กำหนดทั้ง ID และ Secret ครบแล้ว บัญชีที่กลับมาจาก Google/LINE จะเชื่อมกับบัญชีเดิมเฉพาะเมื่อมีอีเมลที่ผู้ให้บริการยืนยันและตรงกัน

## รูปแบบ JSON สำหรับนำเข้าข้อสอบ

```json
[
  {
    "type": "single_choice",
    "prompt": "ข้อใดคือจุดประสงค์ของแบบทดสอบทบทวน",
    "choices": ["วัดความรู้ตั้งต้น", "ออกเกียรติบัตรทันที"],
    "answers": ["วัดความรู้ตั้งต้น"],
    "explanation": "คำอธิบายเฉลย",
    "sort_order": 1
  },
  {
    "type": "multiple_choice",
    "prompt": "สื่อใดสามารถนำมาเป็นบทเรียนได้",
    "choices": ["ข้อความ HTML", "วิดีโอ", "Embed", "เฉพาะ PDF เท่านั้น"],
    "answers": ["ข้อความ HTML", "วิดีโอ", "Embed"],
    "sort_order": 2
  },
  {
    "type": "short_answer",
    "prompt": "พิมพ์คำว่า SENA",
    "choices": [],
    "answers": ["SENA", "sena"],
    "sort_order": 3
  }
]
```

## หมายเหตุสำหรับ production

ชุดนี้ใช้ Tailwind CDN เพื่อให้เปิดบน MAMP ได้ง่ายและเริ่มต่อยอดได้เร็ว ถ้านำขึ้น production ควร build Tailwind เป็นไฟล์ CSS คงที่ด้วย Tailwind CLI/PostCSS และเปลี่ยนรหัสผ่าน admin เริ่มต้นทันที

### Student API บน shared hosting

หาก production มี `config/private.php` ระบบจะโหลดไฟล์นี้ก่อนค่า MAMP ใน
`config/config.php` เสมอ ไฟล์ private จะไม่ถูก commit ขึ้น Git และต้องเก็บไว้บน
เซิร์ฟเวอร์เมื่อ deploy ระบบรอบถัดไป

การเข้าสู่ระบบนักศึกษา ศกร. ต้องอัปโหลด `config/student_api.php` ไปพร้อมระบบ
ไฟล์นี้เก็บ URL และ API key สำหรับเรียก `sena_care_school` จากฝั่ง server โดยไม่
ต้องตั้ง environment variable ใน nginx หรือ PHP-FPM
คัดลอก `config/student_api.example.php` เป็น `config/student_api.php` แล้วใส่ค่าจริงบนเครื่องหรือเซิร์ฟเวอร์เท่านั้น
ไฟล์ค่าจริงจะไม่ถูก commit ขึ้น Git เพื่อป้องกัน credential รั่วไหล

ตรวจหลังอัปโหลด:

```text
https://your-domain.example/sena_learning/config/student_api.php
```

ไฟล์ต้องมีอยู่และตอบ body ว่าง ห้ามแสดง API key ออกทาง browser ส่วน API key
ภายในไฟล์ต้องตรงกับ `STUDENT_API_KEY` หรือค่าใดค่าหนึ่งใน `STUDENT_API_KEYS`
ของเว็บ `sena_care_school`
