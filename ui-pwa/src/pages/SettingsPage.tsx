import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { DocumentTemplatesPanel } from '@/shared/forms/DocumentTemplatesPanel'
import {
  PageHeader, Button, TextField, TextAreaField, NumberField, SwitchField, MediaUpload, useToast, SkeletonBlock,
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
  print_phone?: string | null
  print_address?: string | null
  print_commercial_register?: string | null
  print_tax_info?: string | null
  contract_terms?: string | null
}

export function SettingsPage() {
  const toast = useToast()
  const { isIntegrator, can } = useAuth()
  const [data, setData] = useState<any>(null)
  const [saving, setSaving] = useState(false)
  const [centerName, setCenterName] = useState('')
  const [printFooter, setPrintFooter] = useState('')
  const [logoPath, setLogoPath] = useState('')
  const [printPhone, setPrintPhone] = useState('')
  const [printAddress, setPrintAddress] = useState('')
  const [printCr, setPrintCr] = useState('')
  const [printTax, setPrintTax] = useState('')
  const [contractTerms, setContractTerms] = useState('')
  const [ops, setOps] = useState<Ops>({})

  useEffect(() => {
    api<{ data: any }>('/settings/tenant').then((r) => {
      setData(r.data)
      setCenterName(r.data?.center_name || '')
      setPrintFooter(r.data?.print_footer || '')
      setLogoPath(r.data?.logo_path || '')
      const operational = r.data?.operational || {}
      setPrintPhone(operational.print_phone || '')
      setPrintAddress(operational.print_address || '')
      setPrintCr(operational.print_commercial_register || '')
      setPrintTax(operational.print_tax_info || '')
      setContractTerms(operational.contract_terms || '')
      setOps({
        default_daily_km: operational.default_daily_km ?? 200,
        excess_km_rate: operational.excess_km_rate ?? 5,
        currency: operational.currency ?? 'EGP',
        block_expired_license: operational.block_expired_license ?? true,
        block_expired_vehicle_docs: operational.block_expired_vehicle_docs ?? true,
        require_handover_signature: operational.require_handover_signature ?? true,
        require_return_signature_always: operational.require_return_signature_always ?? false,
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
          logo_path: logoPath || null,
          print_phone: printPhone || null,
          print_address: printAddress || null,
          print_commercial_register: printCr || null,
          print_tax_info: printTax || null,
          contract_terms: contractTerms || null,
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
          <div className="rpm-dense-row">
            <div>
              <strong>تعدد الفروع</strong>
              <p className="muted">{data.multi_branch ? 'مفعّل' : 'فرع واحد (الافتراضي)'}</p>
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
        <div className="rpm-panel__head"><h3>قالب وبنود العقد والطباعة</h3></div>
        <p className="muted" style={{ marginBlockEnd: '0.75rem' }}>
          يتحكم في بيانات الترويسة والتذييل وبنود العقد المطبوعة (العقد · الفاتورة · سند القبض).
        </p>
        <div className="rpm-form-grid">
          <TextField label="اسم الشركة على المطبوعات" value={centerName} onChange={(e) => setCenterName(e.target.value)} />
          <TextField label="هاتف الشركة" value={printPhone} onChange={(e) => setPrintPhone(e.target.value)} />
          <TextField label="عنوان الشركة" value={printAddress} onChange={(e) => setPrintAddress(e.target.value)} />
          <TextField label="السجل التجاري" value={printCr} onChange={(e) => setPrintCr(e.target.value)} />
          <TextField label="البطاقة الضريبية / الرقم الضريبي" value={printTax} onChange={(e) => setPrintTax(e.target.value)} />
          <MediaUpload
            label="شعار الشركة على المطبوعات"
            collection="tenant"
            purpose="logo"
            value={logoPath || null}
            onChange={(path) => setLogoPath(path || '')}
            accept="image"
          />
          <TextAreaField
            label="بنود وشروط العقد"
            value={contractTerms}
            onChange={(e) => setContractTerms(e.target.value)}
            hint="تظهر في نهاية العقد المطبوع"
          />
          <TextAreaField
            label="ملاحظات التذييل"
            value={printFooter}
            onChange={(e) => setPrintFooter(e.target.value)}
            hint="سطر ختامي على العقد والفاتورة وسند القبض"
          />
        </div>
      </section>

      <section className="rpm-stack" style={{ gap: '0.75rem' }}>
        <header className="rpm-panel__head" style={{ margin: 0 }}>
          <h3 style={{ margin: 0 }}>قوالب العقود والمستندات</h3>
        </header>
        <DocumentTemplatesPanel />
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
            label="منع اعتماد عقد بمستند عربية منتهٍ"
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
    </div>
  )
}
