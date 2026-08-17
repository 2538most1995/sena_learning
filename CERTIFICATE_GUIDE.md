# คู่มือโครงสร้างระบบเกียรติบัตร

เอกสารนี้สรุปแนวคิดและโครงสร้างระบบเกียรติบัตรของ SENA Learning เพื่อใช้เป็นต้นแบบกับโปรเจ็คอื่น โดยแยกเป็นส่วนข้อมูล, ตัวออกแบบ, ตัวแสดงผล, การส่งออกไฟล์ และรายการตรวจสอบก่อนใช้งานจริง

## เป้าหมายของระบบ

- ผู้ดูแลระบบออกแบบเกียรติบัตรได้เองต่อหลักสูตร
- ผู้เรียนเห็นเกียรติบัตรได้เมื่อผ่านเกณฑ์เท่านั้น
- หน้าออกแบบ, หน้าผู้เรียน, PNG และ PDF ใช้ layout เดียวกัน
- รองรับรูปพื้นหลัง, โลโก้, ลายเซ็น, ข้อความหลัก และองค์ประกอบที่เพิ่มเอง
- ตำแหน่งทั้งหมดเก็บเป็น JSON เพื่อคัดลอกหรือย้ายไปใช้กับหลักสูตร/โปรเจ็คอื่นได้ง่าย

## ไฟล์หลักในโปรเจ็คนี้

| ไฟล์ | หน้าที่ |
| --- | --- |
| `admin/certificate_settings.php` | หน้าออกแบบเกียรติบัตรสำหรับผู้ดูแล |
| `certificate.php` | หน้าแสดงเกียรติบัตรของผู้เรียนและปุ่ม export |
| `includes/certificate_renderer.php` | renderer กลางที่ใช้สร้าง canvas เดียวกันทุกหน้า |
| `includes/helpers.php` | default layout, save/load settings, upload, clean JSON |
| `assets/css/app.css` | CSS ของ canvas, element, preview และ print |
| `sql/schema.sql` | ตาราง `certificate_settings` สำหรับ fresh install |

## หลักการออกแบบ

ระบบนี้ใช้แนวคิดสำคัญ 5 ข้อ

1. ใช้พื้นที่ออกแบบคงที่ `1024 x 724` px ซึ่งเทียบกับ A4 แนวนอนตอน export/print
2. ทุกองค์ประกอบบนเกียรติบัตรเป็น `position:absolute`
3. ค่า `x` และ `y` เก็บเป็นเปอร์เซ็นต์ `0-100` เพื่อให้ย้าย layout ได้ง่าย
4. ค่า `w`, `h`, `fontSize`, `rotate`, `color`, `fontFamily`, `fontWeight`, `textAlign` เก็บเพิ่มใน JSON เมื่อผู้ดูแลปรับแต่ง
5. ใช้ renderer กลางตัวเดียวสำหรับ preview, output, PNG และ PDF เพื่อลดปัญหาหน้าจริงไม่ตรงกับหน้าตัวอย่าง

## Data Model

ตารางหลักสำหรับระบบเกียรติบัตรคือ `certificate_settings`

```sql
CREATE TABLE IF NOT EXISTS certificate_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL UNIQUE,
    background_image VARCHAR(500) NULL,
    logo_image VARCHAR(500) NULL,
    signature_image VARCHAR(500) NULL,
    issuer_name VARCHAR(255) NOT NULL DEFAULT 'SENA Learning Center',
    signature_name VARCHAR(255) NOT NULL DEFAULT 'ผู้อำนวยการหลักสูตร',
    title_text VARCHAR(255) NOT NULL DEFAULT 'เกียรติบัตรการผ่านหลักสูตร',
    body_text TEXT NULL,
    positions JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

ถ้านำไปใช้กับโปรเจ็คอื่น ให้เปลี่ยน `course_id` เป็น foreign key ของ domain นั้น เช่น `event_id`, `training_id`, `program_id` หรือ `project_id`

## ตัวอย่าง JSON Layout

```json
{
  "background": { "x": 50, "y": 50, "w": 1024, "h": 724 },
  "logo": { "x": 50, "y": 12, "w": 180, "h": 70 },
  "title": {
    "x": 50,
    "y": 25,
    "fontSize": 35,
    "color": "#92400e",
    "fontFamily": "Sarabun",
    "fontWeight": "800"
  },
  "name": {
    "x": 50,
    "y": 37,
    "fontSize": 49,
    "color": "#0f766e",
    "fontWeight": "900"
  },
  "body": { "x": 50, "y": 51, "w": 550, "fontSize": 22 },
  "course": { "x": 50, "y": 64, "fontSize": 22 },
  "signature_image": { "x": 50, "y": 75, "w": 230, "h": 82 },
  "signature": { "x": 50, "y": 78 },
  "issuer": { "x": 50, "y": 84.5 },
  "code": { "x": 50, "y": 91 },
  "custom_text_1710000000000": {
    "x": 50,
    "y": 70,
    "text": "ข้อความเพิ่มเติม",
    "fontSize": 18,
    "color": "#0f172a"
  },
  "custom_image_1710000000001": {
    "x": 80,
    "y": 20,
    "w": 120,
    "h": 120,
    "src": "storage/uploads/certificates/seal.png"
  }
}
```

## องค์ประกอบมาตรฐาน

| Key | ความหมาย |
| --- | --- |
| `background` | รูปพื้นหลังเต็มใบ |
| `logo` | โลโก้หน่วยงานหรือหลักสูตร |
| `title` | ชื่อเกียรติบัตร |
| `name` | ชื่อผู้เรียน/ผู้รับ |
| `body` | ข้อความรับรอง |
| `course` | ชื่อหลักสูตร/กิจกรรม |
| `signature_image` | รูปลายเซ็น |
| `signature` | ชื่อผู้ลงนาม |
| `issuer` | ตำแหน่ง/หน่วยงานผู้ออก |
| `code` | รหัสเกียรติบัตรและวันที่ออก |
| `custom_text_*` | ข้อความที่เพิ่มเอง |
| `custom_image_*` | รูปภาพที่เพิ่มเอง |

## Placeholder ในข้อความ

ข้อความใน `body_text` สามารถใช้ token แล้วแทนค่าตอน render

| Token | ค่าแทนที่ |
| --- | --- |
| `{{name}}` | ชื่อผู้เรียน |
| `{{course}}` | ชื่อหลักสูตร |
| `{{code}}` | รหัสเกียรติบัตร |
| `{{date}}` | วันที่ออก |

ตัวอย่าง

```text
เพื่อแสดงว่า {{name}} ได้ผ่านการเรียนรู้ในหลักสูตร {{course}} ตามเกณฑ์ที่กำหนด
```

## Renderer กลาง

หัวใจของระบบคือ renderer กลางที่รับ `settings` และข้อมูลผู้เรียน แล้วสร้าง HTML canvas แบบเดียวกันทุกที่

```php
render_certificate_canvas($settings, $attempt, [
    'id' => 'certificate-output',
    'canvas_classes' => 'certificate-output',
]);
```

หน้า admin ส่ง option เพิ่มเพื่อให้ลาก/แก้ไขได้

```php
render_certificate_canvas($settings, $sampleAttempt, [
    'id' => 'certificate-preview',
    'interactive' => true,
    'show_placeholders' => true,
]);
```

ข้อดีคือเมื่อปรับ CSS หรือ logic ของ element ครั้งเดียว ทุกหน้าจะได้ผลเหมือนกัน

## CSS สำคัญ

```css
.certificate-layout-canvas {
    width: 1024px;
    height: 724px;
}

.certificate-element {
    position: absolute;
    transform: translate(-50%, -50%);
    transform-origin: center center;
    box-sizing: border-box;
    text-align: center;
}

.certificate-output {
    width: 1024px !important;
    height: 724px !important;
    max-width: none !important;
}
```

ห้ามใช้ responsive CSS ไปเปลี่ยนขนาดจริงของ `.certificate-output` ตอน export เพราะ `html2canvas` ต้องจับภาพจากพื้นผิวจริงที่คงที่

## Flow หน้า Admin Designer

1. โหลด `certificate_settings` จากฐานข้อมูล
2. ถ้าไม่มี settings ให้ใช้ `default_certificate_positions()`
3. render canvas แบบ `interactive`
4. ผู้ดูแลลาก, ย่อขยาย, หมุน, แก้ข้อความ หรือเพิ่มรูป/ข้อความ
5. JavaScript อัปเดต input hidden ชื่อ `positions` เป็น JSON
6. เมื่อกดบันทึก backend เรียก `clean_certificate_positions()`
7. บันทึก settings ด้วย `INSERT ... ON DUPLICATE KEY UPDATE`

## Flow หน้าผู้เรียน

1. ตรวจ attempt token และ user session
2. อนุญาตเฉพาะ attempt ที่ `status = passed`
3. ถ้า learner มี certificate เดิมของหลักสูตรเดียวกัน ให้ redirect ไปใบเดิม
4. โหลด settings ของหลักสูตร
5. render canvas ด้วย renderer กลาง
6. ใช้ `html2canvas` สร้าง preview image บนหน้าจอ
7. ผู้เรียน export เป็น PNG หรือ PDF ได้

## Export PNG/PDF

ระบบใช้ library ฝั่ง browser

- `html2canvas` สำหรับจับ HTML certificate เป็น canvas
- `jsPDF` สำหรับสร้าง PDF A4 landscape

แนวทางที่ควรรักษาไว้

- รอให้รูปภาพทุกไฟล์โหลดเสร็จก่อน export
- รอ `document.fonts.ready` ก่อนจับภาพ
- ใช้ `useCORS: true` ถ้ามีรูปจาก URL ภายนอก
- ใช้ scale สูง เช่น `3` สำหรับ preview และ `4` สำหรับ export
- PDF ใช้ A4 landscape ขนาด `297 x 210 mm`
- สำหรับ in-app browser เช่น LINE/Facebook ให้ fallback เป็น dialog สำหรับบันทึก/แชร์

## Upload และความปลอดภัย

ควรตรวจไฟล์ upload ด้วย MIME type จริง ไม่ใช่นามสกุลอย่างเดียว

```php
$allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
$mime = mime_content_type($_FILES[$field]['tmp_name']) ?: '';
if (!isset($allowed[$mime])) {
    throw new RuntimeException('ไฟล์ต้องเป็น PNG, JPG หรือ WEBP');
}
```

รายการที่ต้องระวัง

- route admin ต้องมี `require_admin()`
- route ผู้เรียนต้องตรวจ attempt token และ user session
- output ข้อความทุกจุดต้อง escape ด้วย `e()`
- path รูป local ควรแปลงผ่าน helper เช่น `public_upload_url()`
- อย่า render raw HTML จากผู้ดูแลถ้าไม่จำเป็น
- จำกัดขนาด `w` และ `h` ใน JSON เพื่อกัน layout พังหรือ payload แปลก

## การคัดลอก Layout ไปหลักสูตรอื่น

แนวทางที่ดีคือคัดลอกเฉพาะ `positions` เพื่อให้ตำแหน่ง, ขนาด, ฟอนต์, สี และการหมุนเหมือนต้นแบบ แต่ไม่ทับรูปและข้อความหลักของหลักสูตรปลายทาง

```text
คัดลอก: positions
ไม่ควรทับอัตโนมัติ: background_image, logo_image, signature_image, title_text, body_text, issuer_name
```

วิธีนี้ทำให้ layout reuse ได้โดยไม่ทำให้ asset ของหลักสูตรใหม่หาย

## Checklist เมื่อนำไปใช้กับโปรเจ็คอื่น

- สร้างตาราง settings และผูกกับ entity หลักของโปรเจ็ค
- สร้าง renderer กลางก่อนสร้างหน้า admin/export
- กำหนด canvas คงที่ เช่น `1024 x 724`
- เก็บ `x/y` เป็นเปอร์เซ็นต์ และเก็บขนาดเป็น px
- ทำ helper สำหรับ default positions
- ทำ helper สำหรับ clean/normalize positions
- ตรวจ MIME upload และเก็บไฟล์ในโฟลเดอร์เฉพาะ
- ใช้ renderer เดียวกันทั้ง preview, output, PNG และ PDF
- ทดสอบชื่อผู้เรียนยาว, ชื่อหลักสูตรยาว, ไม่มีรูป, รูปใหญ่, mobile preview และ print/export

## Acceptance Criteria

ระบบเกียรติบัตรพร้อมใช้งานเมื่อผ่านเงื่อนไขนี้

- ผู้ดูแลบันทึก layout แล้วเปิดใหม่ตำแหน่งยังตรง
- ผู้เรียนที่ยังไม่ผ่านเปิดเกียรติบัตรไม่ได้
- ผู้เรียนที่ผ่านแล้วเห็นเกียรติบัตรถูกต้อง
- preview, PNG และ PDF มี layout ตรงกัน
- รูปพื้นหลัง โลโก้ และลายเซ็นไม่บิดเบี้ยวโดยไม่ตั้งใจ
- ข้อความยาวไม่หลุดออกนอกใบแบบรุนแรง
- route ที่เกี่ยวข้องไม่มีข้อความ user/admin ที่ render แบบไม่ escape
- ไฟล์ทดลองและ upload ชั่วคราวถูกลบหลังทดสอบ

