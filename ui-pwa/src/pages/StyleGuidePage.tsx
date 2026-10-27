import { useEffect, useState } from 'react'
import './StyleGuidePage.css'

type Mode = 'light' | 'dark'

const typeScale = [
  { name: 'Display', className: 'sg-type-display', sample: 'آر بي إم · إدارة التأجير' },
  { name: 'H1', className: 'sg-type-h1', sample: 'لوحة التشغيل اليومية' },
  { name: 'H2', className: 'sg-type-h2', sample: 'حالة الأسطول الآن' },
  { name: 'Body', className: 'sg-type-body', sample: 'عقد نشط · تسليم اليوم · وديعة محفوظة حتى الاستلام.' },
  { name: 'Caption', className: 'sg-type-caption', sample: 'آخر تحديث منذ دقيقتين' },
  { name: 'Numeric', className: 'sg-type-num', sample: '12,450.00 ج.م · 148,320 كم' },
]

const statuses = [
  { label: 'متاح', tone: 'success' },
  { label: 'مؤجّر', tone: 'brand' },
  { label: 'محجوز', tone: 'info' },
  { label: 'صيانة', tone: 'warning' },
  { label: 'متأخر', tone: 'danger' },
  { label: 'مسودة', tone: 'muted' },
]

const spacing = [4, 8, 12, 16, 20, 24, 32, 40, 48]

export function StyleGuidePage() {
  const [mode, setMode] = useState<Mode>('light')

  useEffect(() => {
    document.documentElement.dataset.sgPreview = '1'
    return () => {
      delete document.documentElement.dataset.sgPreview
    }
  }, [])

  return (
    <div className={`sg-root sg-root--${mode}`} data-theme={mode}>
      <header className="sg-hero">
        <div className="sg-hero__glow" aria-hidden />
        <p className="sg-eyebrow">RPM Design System · Proposal v1</p>
        <h1>نظام الإشارة · Signal</h1>
        <p className="sg-lede">
          هوية بصرية واحدة للتشغيل اليومي: هادئة، واثقة، فاخرة — بدون مظهر قوالب الإدارة.
          هذه الصفحة للموافقة فقط؛ لم يُمسّ الشِل ولا اللوحة بعد.
        </p>
        <div className="sg-hero__actions">
          <div className="sg-segment" role="group" aria-label="معاينة الوضع">
            <button
              type="button"
              className={mode === 'light' ? 'is-active' : ''}
              onClick={() => setMode('light')}
            >
              فاتح
            </button>
            <button
              type="button"
              className={mode === 'dark' ? 'is-active' : ''}
              onClick={() => setMode('dark')}
            >
              داكن
            </button>
          </div>
          <a className="sg-link" href="/">العودة للتطبيق الحالي</a>
        </div>
      </header>

      <main className="sg-main">
        <section className="sg-section">
          <div className="sg-section__head">
            <h2>١) التوكنز</h2>
            <p>قاعدة محايدة دافئة + حبر عميق + إشارة واحدة + ٤ حالات دلالية.</p>
          </div>
          <div className="sg-swatch-grid">
            {[
              ['--sg-bg', 'خلفية'],
              ['--sg-surface', 'سطح'],
              ['--sg-elevated', 'مرتفع'],
              ['--sg-ink', 'حبر'],
              ['--sg-muted', 'ثانوي'],
              ['--sg-accent', 'إشارة'],
              ['--sg-success', 'نجاح'],
              ['--sg-warning', 'تحذير'],
              ['--sg-danger', 'خطر'],
              ['--sg-info', 'معلومة'],
              ['--sg-border', 'حد'],
            ].map(([token, label]) => (
              <article key={token} className="sg-swatch">
                <div className="sg-swatch__chip" style={{ background: `var(${token})` }} />
                <strong>{label}</strong>
                <code>{token}</code>
              </article>
            ))}
          </div>
        </section>

        <section className="sg-section">
          <div className="sg-section__head">
            <h2>٢) الخطوط</h2>
            <p>
              <strong>Cairo</strong> للعناوين (ثقة عربية واضحة) ·{' '}
              <strong>IBM Plex Sans Arabic</strong> للنصوص والأرقام المجدولة (مال/كم).
            </p>
          </div>
          <div className="sg-type-list">
            {typeScale.map((row) => (
              <div key={row.name} className="sg-type-row">
                <span className="sg-type-meta">{row.name}</span>
                <span className={row.className}>{row.sample}</span>
              </div>
            ))}
          </div>
        </section>

        <section className="sg-section">
          <div className="sg-section__head">
            <h2>٣) الأزرار والحالات</h2>
          </div>
          <div className="sg-row wrap">
            <button type="button" className="sg-btn sg-btn--primary">إجراء أساسي</button>
            <button type="button" className="sg-btn sg-btn--secondary">ثانوي</button>
            <button type="button" className="sg-btn sg-btn--ghost">شفاف</button>
            <button type="button" className="sg-btn sg-btn--danger">خطر</button>
            <button type="button" className="sg-btn sg-btn--primary" disabled>تحميل…</button>
          </div>
          <div className="sg-row wrap" style={{ marginTop: '1rem' }}>
            {statuses.map((s) => (
              <span key={s.label} className={`sg-badge sg-badge--${s.tone}`}>{s.label}</span>
            ))}
          </div>
        </section>

        <section className="sg-section">
          <div className="sg-section__head">
            <h2>٤) الحقول</h2>
            <p>عائلة موحّدة · تسمية فوق · خطأ تحت · وحدة للمال/كم.</p>
          </div>
          <div className="sg-form-grid">
            <label className="sg-field">
              <span>اسم العميل</span>
              <input defaultValue="أحمد محمود" />
            </label>
            <label className="sg-field">
              <span>الإيجار اليومي</span>
              <div className="sg-input-affix">
                <input className="sg-num" defaultValue="1250.00" />
                <em>ج.م</em>
              </div>
            </label>
            <label className="sg-field has-error">
              <span>رقم اللوحة</span>
              <input defaultValue="" placeholder="أ ب ج 1234" />
              <small>هذا الحقل مطلوب</small>
            </label>
            <label className="sg-field">
              <span>العداد</span>
              <div className="sg-input-affix">
                <input className="sg-num" defaultValue="48210" />
                <em>كم</em>
              </div>
            </label>
          </div>
        </section>

        <section className="sg-section">
          <div className="sg-section__head">
            <h2>٥) كروت Bento + صورة البطل</h2>
          </div>
          <div className="sg-bento">
            <article className="sg-card sg-card--kpi">
              <span>إشغال الأسطول</span>
              <strong className="sg-num">78%</strong>
              <em>+4% عن أمس</em>
            </article>
            <article className="sg-card sg-card--kpi">
              <span>إيراد متوقع</span>
              <strong className="sg-num">48,200</strong>
              <em>ج.م اليوم</em>
            </article>
            <article className="sg-card sg-card--hero">
              <div className="sg-car-photo" role="img" aria-label="معاينة صورة سيارة">
                <div className="sg-car-photo__fallback">لا توجد صورة</div>
              </div>
              <div className="sg-car-meta">
                <div>
                  <strong>تويوتا كورولا 2024</strong>
                  <p>أ ب ج 1234 · أوتوماتيك</p>
                </div>
                <span className="sg-badge sg-badge--success">متاح</span>
              </div>
            </article>
            <article className="sg-card sg-card--action-needed">
              <h3>يحتاج إجراء الآن</h3>
              <ul>
                <li>عقد #1042 متأخر يومين</li>
                <li>رخصة مركبة تنتهي خلال 5 أيام</li>
                <li>أمر شغل بانتظار اعتماد</li>
              </ul>
            </article>
          </div>
        </section>

        <section className="sg-section">
          <div className="sg-section__head">
            <h2>٦) Quick Actions الموحّد</h2>
            <p>
              Desktop: شريط يظهر عند الـ hover أو قائمة ⋯ · Mobile: سواييب على الكارت أو Bottom Sheet.
              نفس قائمة الإجراءات في كل الوحدات.
            </p>
          </div>
          <div className="sg-qa-demo">
            <div className="sg-qa-row">
              <div>
                <strong>عقد #1048 · أحمد محمود</strong>
                <p>كورولا · نشط · يرجع غداً</p>
              </div>
              <div className="sg-qa-bar" aria-label="إجراءات سريعة">
                <button type="button">تعديل سريع</button>
                <button type="button">إلى الصيانة</button>
                <button type="button">تغيير الحالة</button>
                <button type="button" className="is-more" aria-label="المزيد">⋯</button>
              </div>
            </div>
            <div className="sg-swipe-card" aria-label="معاينة سواييب الموبايل">
              <div className="sg-swipe-card__reveal sg-swipe-card__reveal--start">تعديل</div>
              <div className="sg-swipe-card__body">
                <strong>كارت قائمة — اسحب للإجراء</strong>
                <p>على الموبايل: سواييب يكشف الإجراءات بدل جدول أفقي</p>
              </div>
              <div className="sg-swipe-card__reveal sg-swipe-card__reveal--end">صيانة</div>
            </div>
          </div>
        </section>

        <section className="sg-section">
          <div className="sg-section__head">
            <h2>٧) البوب أب / الشيت</h2>
            <p>ديسكتوب: مودال مركزي كبير أو شيت من حافة البداية · موبايل: Full-screen bottom sheet بمقبض سحب.</p>
          </div>
          <div className="sg-modal-preview" role="dialog" aria-label="معاينة مودال">
            <div className="sg-modal-preview__handle" aria-hidden />
            <header>
              <h3>عقد جديد</h3>
              <button type="button" className="sg-icon-x" aria-label="إغلاق">×</button>
            </header>
            <div className="sg-modal-preview__body">
              <p>رأس لاصق · جسم يتمرّر · تذييل لاصق بالأزرار. Focus trap + ESC + تأكيد عند وجود تغييرات غير محفوظة.</p>
              <div className="sg-steps">
                <span className="is-done">العميل</span>
                <span className="is-active">السيارة</span>
                <span>التواريخ</span>
                <span>الدفع</span>
                <span>المراجعة</span>
              </div>
            </div>
            <footer>
              <button type="button" className="sg-btn sg-btn--ghost">إلغاء</button>
              <button type="button" className="sg-btn sg-btn--primary">التالي</button>
            </footer>
          </div>
        </section>

        <section className="sg-section">
          <div className="sg-section__head">
            <h2>٨) المسافات · نصف القطر · الظلال</h2>
          </div>
          <div className="sg-spacing">
            {spacing.map((n) => (
              <div key={n} className="sg-spacing__item">
                <i style={{ width: n, height: n }} />
                <span>{n}</span>
              </div>
            ))}
          </div>
          <div className="sg-radius-row">
            <div className="sg-radius-demo" style={{ borderRadius: 12 }}>12</div>
            <div className="sg-radius-demo" style={{ borderRadius: 16 }}>16</div>
            <div className="sg-radius-demo" style={{ borderRadius: 20 }}>20</div>
            <div className="sg-radius-demo" style={{ borderRadius: 24 }}>24</div>
          </div>
          <div className="sg-shadow-row">
            <div className="sg-shadow-demo sg-shadow-demo--sm">sm</div>
            <div className="sg-shadow-demo sg-shadow-demo--md">md</div>
            <div className="sg-shadow-demo sg-shadow-demo--lg">lg</div>
          </div>
        </section>

        <section className="sg-section sg-section--decision">
          <h2>قرار الموافقة</h2>
          <ul>
            <li>الستاك الحالي: React 19 + Vite + CSS مخصّص — بدون Tailwind/MUI. نبقيه ونوسّعه.</li>
            <li>بعد موافقتك: نضيف فقط <code>framer-motion</code> و<code>recharts</code> — بلا مكتبات UI متعارضة.</li>
            <li>التنفيذ الأول بعد الموافقة: App Shell (ديسكتوب+PWA) + Dashboard فقط، ثم نتوقف للمراجعة.</li>
            <li>الموافقة تعني تحديث <code>docs/معايير-المشروع-الملزمة.md</code> ليشمل الوضع الداكن الاختياري والتوكنز الجديدة.</li>
          </ul>
          <p className="sg-wait">في انتظار موافقتك على نظام «Signal» قبل أي تنفيذ على الشِل أو اللوحة.</p>
        </section>
      </main>
    </div>
  )
}
