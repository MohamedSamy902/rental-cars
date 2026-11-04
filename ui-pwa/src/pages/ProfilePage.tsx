import { useEffect, useState } from 'react'
import { ProfileSheet } from '@/shared/forms/ProfileSheet'
import { PageHeader, Button, Badge } from '@/shared/ui'
import { useAuth } from '@/shared/auth/AuthContext'
import { IconEdit } from '@/shared/ui/Icons'

/**
 * Self-service profile route — available to any authenticated user.
 * No RBAC permission gate (App Protected wrapper is auth-only).
 */
export function ProfilePage() {
  const { user } = useAuth()
  const [open, setOpen] = useState(true)

  useEffect(() => {
    setOpen(true)
  }, [])

  return (
    <div className="rpm-page">
      <PageHeader
        title="حسابي الشخصي"
        subtitle="عرض وتعديل بيانات حسابك — متاح لكل مستخدم مسجّل الدخول"
        action={(
          <Button variant="secondary" onClick={() => setOpen(true)}>
            <span className="rpm-btn__icon" aria-hidden>
              <IconEdit size={16} />
            </span>
            <span className="rpm-btn__label">تعديل البيانات</span>
          </Button>
        )}
      />

      <section className="rpm-surface" style={{ padding: '1.25rem', borderRadius: 20, maxWidth: 560 }}>
        <div style={{ display: 'grid', gap: '0.85rem' }}>
          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>الاسم الكامل</div>
            <strong>{user?.name || '—'}</strong>
          </div>
          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>رقم الموبايل</div>
            <strong dir="ltr" style={{ unicodeBidi: 'plaintext' }}>{user?.phone || '—'}</strong>
          </div>
          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>البريد الإلكتروني</div>
            <strong dir="ltr" style={{ unicodeBidi: 'plaintext' }}>{user?.email || '—'}</strong>
          </div>
          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBlockEnd: '0.35rem' }}>الدور الوظيفي</div>
            <Badge tone="steel" dot={false}>{user?.role || 'مستخدم'}</Badge>
          </div>
        </div>
      </section>

      <ProfileSheet open={open} onClose={() => setOpen(false)} />
    </div>
  )
}
