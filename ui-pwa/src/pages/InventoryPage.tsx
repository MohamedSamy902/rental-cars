import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { PageHeader, Button, Sheet, Fab, TextField, NumberField, MoneyField, StatusPill, useToast, SkeletonBlock, EmptyState } from '@/shared/ui'
import './pages.css'

type Item = {
  id: number
  code: string
  name: string
  category: string
  unit: string
  remaining: number
  reorder_level: number
  is_low: boolean
}

export function InventoryPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Item[]>([])
  const [loading, setLoading] = useState(true)
  const [openItem, setOpenItem] = useState(false)
  const [openBatch, setOpenBatch] = useState(false)
  const [itemForm, setItemForm] = useState({ code: '', name: '', category: 'زيوت', unit: 'لتر', reorder_level: '5' })
  const [batchForm, setBatchForm] = useState({ inventory_item_id: '', received_on: new Date().toISOString().slice(0, 10), quantity: '', unit_cost: '', supplier: '' })

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Item[] }>('/inventory')
      setRows(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  async function saveItem() {
    try {
      await api('/inventory/items', { method: 'POST', json: { ...itemForm, reorder_level: Number(itemForm.reorder_level) } })
      toast.success('تم إضافة الصنف.')
      setOpenItem(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function saveBatch() {
    try {
      await api('/inventory/batches', {
        method: 'POST',
        json: {
          ...batchForm,
          inventory_item_id: Number(batchForm.inventory_item_id),
          quantity: Number(batchForm.quantity),
          unit_cost: Number(batchForm.unit_cost),
        },
      })
      toast.success('تم إدخال الدفعة (FIFO).')
      setOpenBatch(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="المخزون"
        subtitle="صرف بالأقدم أولاً من أمر الشغل — بلا سند صرف منفصل"
        action={
          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <Button variant="secondary" onClick={() => setOpenBatch(true)}>إدخال دفعة</Button>
            <Button onClick={() => setOpenItem(true)}>صنف جديد</Button>
          </div>
        }
        mobileFab
      />
      <Fab label="صنف جديد" onClick={() => setOpenItem(true)} />
      {loading ? <SkeletonBlock rows={5} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="لا توجد أصناف" /> : null}
      <div className="rpm-card-list">
        {rows.map((item) => (
          <article key={item.id} className="rpm-card">
            <div className="rpm-card__row">
              <strong>{item.name}</strong>
              {item.is_low ? <StatusPill status="maintenance" label="تحت حد التنبيه" /> : <StatusPill status="available" label="رصيد كافٍ" />}
            </div>
            <p className="muted">{item.code} · {item.category} · {item.unit}</p>
            <div className="rpm-card__meta">
              <span>المتبقي: {item.remaining}</span>
              <span>حد التنبيه: {item.reorder_level}</span>
            </div>
          </article>
        ))}
      </div>

      <Sheet open={openItem} title="صنف مخزون" onClose={() => setOpenItem(false)} footer={<><Button onClick={saveItem}>حفظ</Button><Button variant="secondary" onClick={() => setOpenItem(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <TextField label="الكود" required value={itemForm.code} onChange={(e) => setItemForm({ ...itemForm, code: e.target.value })} />
          <TextField label="الاسم" required value={itemForm.name} onChange={(e) => setItemForm({ ...itemForm, name: e.target.value })} />
          <TextField label="التصنيف" required value={itemForm.category} onChange={(e) => setItemForm({ ...itemForm, category: e.target.value })} />
          <TextField label="وحدة القياس" required value={itemForm.unit} onChange={(e) => setItemForm({ ...itemForm, unit: e.target.value })} />
          <NumberField label="حد تنبيه النقص" required value={itemForm.reorder_level} onChange={(e) => setItemForm({ ...itemForm, reorder_level: e.target.value })} />
        </div>
      </Sheet>

      <Sheet open={openBatch} title="دفعة إدخال" onClose={() => setOpenBatch(false)} footer={<><Button onClick={saveBatch}>حفظ</Button><Button variant="secondary" onClick={() => setOpenBatch(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <TextField label="معرّف الصنف" required value={batchForm.inventory_item_id} onChange={(e) => setBatchForm({ ...batchForm, inventory_item_id: e.target.value })} hint="من قائمة الأصناف" />
          <TextField label="التاريخ" required type="date" value={batchForm.received_on} onChange={(e) => setBatchForm({ ...batchForm, received_on: e.target.value })} />
          <NumberField label="الكمية" required value={batchForm.quantity} onChange={(e) => setBatchForm({ ...batchForm, quantity: e.target.value })} />
          <MoneyField label="سعر الوحدة" required value={batchForm.unit_cost} onChange={(e) => setBatchForm({ ...batchForm, unit_cost: e.target.value })} />
          <TextField label="المورد (نص حر)" value={batchForm.supplier} onChange={(e) => setBatchForm({ ...batchForm, supplier: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
