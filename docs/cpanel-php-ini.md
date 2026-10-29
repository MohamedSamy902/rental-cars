# قيم PHP المطلوبة على cPanel (رفع الملفات)

عيّنها من **MultiPHP INI Editor** (أو `.user.ini` في `public/`):

| المفتاح | القيمة المطلوبة |
|---------|-----------------|
| `upload_max_filesize` | `64M` |
| `post_max_size` | `72M` |
| `max_file_uploads` | `20` |
| `max_execution_time` | `120` |
| `memory_limit` | `256M` |
| `file_uploads` | `On` |

ملاحظات:
- `post_max_size` أكبر من أكبر فيديو (50 ميجا) بهامش multipart.
- حدود التطبيق في `config/uploads.php`: صورة 12 ميجا · فيديو 50 ميجا · PDF 12 ميجا.
- إن بقي PHP على 2M ستظهر رسالة عربية عبر المعالج العام، لكن الرفع سيفشل حتى تُرفع الحدود.
- لا Livewire/Filament في هذا المشروع؛ الرفع عبر `POST /api/media` فقط.
