import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import {
  PageHeader, Button, TextField, NumberField, SwitchField, useToast, SkeletonBlock,
} from '@/shared/ui'
import './pages.css'

type Ops = {
  default_daily_km?: number
  excess_km_rate?: number
  currency?: string
  block_expired_license?: boolean
  block_expired_vehicle_docs?: boolean
  require_handover_signature?: boolean
  require_return_signature_always?: boolean
}

export function SettingsPage() {
  const toast = useToast()
  const { isIntegrator, can } = useAuth()
  const [data, setData] = useState<any>(null)
  const [saving, setSaving] = useState(false)
  const [centerName, setCenterName] = useState('')
  const [printFooter, setPrintFooter] = useState('')
  const [ops, setOps] = useState<Ops>({})

  useEffect(() => {
    api<{ data: any }>('/settings/tenant').then((r) => {
      setData(r.data)
      setCenterName(r.data?.center_name || '')
      setPrintFooter(r.data?.print_footer || '')
      setOps({
        default_daily_km: r.data?.operational?.default_daily_km ?? 200,
        excess_km_rate: r.data?.operational?.excess_km_rate ?? 5,
        currency: r.data?.operational?.currency ?? 'EGP',
        block_expired_license: r.data?.operational?.block_expired_license ?? true,
        block_expired_vehicle_docs: r.data?.operational?.block_expired_vehicle_docs ?? true,
        require_handover_signature: r.data?.operational?.require_handover_signature ?? true,
        require_return_signature_always: r.data?.operational?.require_return_signature_always ?? false,
      })
    })
  }, [])

  async function save() {
    if (!can('settings.update') && !isIntegrator) {
      toast.error('لا صلاحية لتعديل الإعدادات.')
      return
    }
    setSaving(true)
    try {
      const res = await api<{ data: any; message: string }>('/settings/operational', {
        method: 'PUT',
        json: {
          center_name: centerName,
          print_footer: printFooter,
          operational: ops,
        },
      })
      setData(res.data)
      toast.success(res.message || 'تم الحفظ.')
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  if (!data) return <SkeletonBlock rows={5} />

  return (
    <div className="rpm-stack">
      <PageHeader
        title="الإعدادات"
        subtitle="إعدادات التشغيل اليومية للمعرض"
        action={<Button loading={saving} onClick={() => void save()}>حفظ</Button>}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المركز</h3></div>
        <div className="rpm-form-grid">
          <TextField label="اسم المركز" value={centerName} onChange={(e) => setCenterName(e.target.value)} />
          <TextField label="تذييل الطباعة" value={printFooter} onChange={(e) => setPrintFooter(e.target.value)} />
          <div className="rpm-dense-row">
            <div>
              <strong>تعدد الفروع</strong>
              <p className="muted">{data.multi_branch ? 'مفعّل' : 'فرع واحد (الافتراضي) — يُغيَّر من لوحة المُدمج'}</p>
            </div>
          </div>
          <div className="rpm-dense-row">
            <div>
              <strong>وضع المخزن</strong>
              <p className="muted">{data.inventory_mode === 'unified' ? 'موحّد' : 'لكل فرع'}</p>
            </div>
          </div>
        </div>
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>التشغيل والعقود</h3></div>
        <div className="rpm-form-grid">
          <NumberField
            label="الكيلومتر اليومي الافتراضي"
            value={String(ops.default_daily_km ?? '')}
            onChange={(e) => setOps({ ...ops, default_daily_km: Number(e.target.value) })}
          />
          <NumberField
            label="سعر الكيلومتر الزائد"
            value={String(ops.excess_km_rate ?? '')}
            onChange={(e) => setOps({ ...ops, excess_km_rate: Number(e.target.value) })}
          />
          <SwitchField
            label="منع اعتماد عقد برخصة منتهية"
            checked={Boolean(ops.block_expired_license)}
            onChange={(v) => setOps({ ...ops, block_expired_license: v })}
          />
          <SwitchField
            label="منع اعتماد عقد بمستند سيارة منتهٍ"
            checked={Boolean(ops.block_expired_vehicle_docs)}
            onChange={(v) => setOps({ ...ops, block_expired_vehicle_docs: v })}
          />
          <SwitchField
            label="إلزام توقيع التسليم"
            checked={Boolean(ops.require_handover_signature)}
            onChange={(v) => setOps({ ...ops, require_handover_signature: v })}
          />
          <SwitchField
            label="إلزام توقيع الاستلام دائماً"
            checked={Boolean(ops.require_return_signature_always)}
            onChange={(v) => setOps({ ...ops, require_return_signature_always: v })}
          />
        </div>
      </section>

      {isIntegrator ? (
        <section className="rpm-panel">
          <div className="rpm-panel__head"><h3>لوحة المُدمج</h3></div>
          <p className="muted">فيتشر فلاج الوحدات والألوان وتعدد الفروع — ليست لموظفي المعرض.</p>
          <Link className="rpm-link-btn" to="/integrator">فتح لوحة المُدمج</Link>
        </section>
      ) : null}
    </div>
  )
}
