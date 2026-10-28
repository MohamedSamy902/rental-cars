import { useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { PageHeader, Button, TextField, SwitchField, useToast, SkeletonBlock } from '@/shared/ui'
import './pages.css'

type Flags = Record<string, boolean>

const FLAG_LABELS: Record<string, string> = {
  inventory: 'المخزون',
  petty_cash: 'العهدة',
  waitlist: 'قائمة الانتظار',
  showroom: 'وضع المعرض',
  department_notice: 'إخطار القسم',
  chat: 'المحادثة',
  evaluations: 'التقييمات',
  hr: 'الموارد البشرية',
}

export function IntegratorPage() {
  const { isIntegrator } = useAuth()
  const toast = useToast()
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [centerName, setCenterName] = useState('')
  const [printFooter, setPrintFooter] = useState('')
  const [multiBranch, setMultiBranch] = useState(false)
  const [brand, setBrand] = useState('#0D9488')
  const [flags, setFlags] = useState<Flags>({})

  useEffect(() => {
    if (!isIntegrator) return
    api<{ data: any }>('/settings/tenant')
      .then((r) => {
        setCenterName(r.data?.center_name || '')
        setPrintFooter(r.data?.print_footer || '')
        setMultiBranch(Boolean(r.data?.multi_branch))
        setBrand(r.data?.colors?.brand || '#0D9488')
        setFlags({
          inventory: true,
          petty_cash: true,
          waitlist: true,
          showroom: true,
          department_notice: true,
          chat: true,
          evaluations: true,
          hr: true,
          ...(r.data?.feature_flags || {}),
        })
      })
      .finally(() => setLoading(false))
  }, [isIntegrator])

  if (!isIntegrator) return <Navigate to="/settings" replace />

  async function save() {
    setSaving(true)
    try {
      await api('/integrator/tenant', {
        method: 'PUT',
        json: {
          center_name: centerName,
          print_footer: printFooter,
          multi_branch: multiBranch,
          colors: { brand },
          feature_flags: flags,
        },
      })
      toast.success('تم حفظ إعدادات المُدمج.')
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <SkeletonBlock rows={6} />

  return (
    <div className="rpm-stack">
      <PageHeader
        title="لوحة المُدمج"
        subtitle="خاصة بمقدّم النظام فقط — فيتشر فلاج · ألوان · تعدد فروع · هوية المركز"
        action={<Button loading={saving} onClick={() => void save()}>حفظ</Button>}
      />
      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>هوية المركز</h3></div>
        <div className="rpm-form-grid">
          <TextField label="اسم المركز" value={centerName} onChange={(e) => setCenterName(e.target.value)} />
          <TextField label="تذييل الطباعة" value={printFooter} onChange={(e) => setPrintFooter(e.target.value)} />
          <TextField label="لون العلامة" type="color" value={brand} onChange={(e) => setBrand(e.target.value)} />
          <SwitchField label="تعدد الفروع" checked={multiBranch} onChange={setMultiBranch} />
        </div>
      </section>
      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>فيتشر فلاج الوحدات</h3></div>
        <div className="rpm-form-grid">
          {Object.keys(FLAG_LABELS).map((key) => (
            <SwitchField
              key={key}
              label={FLAG_LABELS[key]}
              checked={Boolean(flags[key])}
              onChange={(v) => setFlags((f) => ({ ...f, [key]: v }))}
            />
          ))}
        </div>
      </section>
    </div>
  )
}
