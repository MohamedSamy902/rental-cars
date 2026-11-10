import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { todayCivilIso } from '@/shared/lib/dates'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import {
  PageHeader, FilterBar, Button, Sheet, Fab, TextField, NumberField, MoneyField, SelectField, StatusPill, useToast, DataTable, IconBox, IconEdit,
  type DataColumn,
} from '@/shared/ui'
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

const emptyItemForm = () => ({ code: '', name: '', category: 'زيوت', unit: 'لتر', reorder_level: '5' })

const columns: DataColumn<Item>[] = [
  {
    id: 'name',
    header: 'الصنف',
    cell: (i) => <strong className="rpm-table__primary">{i.name}</strong>,
    sortValue: (i) => i.name,
    mobile: 'title',
  },
  { id: 'code', header: 'الكود', cell: (i) => <span className="rpm-table__code">{i.code}</span>, sortValue: (i) => i.code, mobile: 'subtitle' },
  { id: 'category', header: 'الفئة', cell: (i) => i.category, sortValue: (i) => i.category },
  { id: 'remaining', header: 'المتبقي', cell: (i) => `${i.remaining} ${i.unit}`, sortValue: (i) => Number(i.remaining) },
  { id: 'reorder', header: 'حد التنبيه', cell: (i) => i.reorder_level, sortValue: (i) => Number(i.reorder_level) },
  {
    id: 'status',
    header: 'الحالة',
    cell: (i) => (i.is_low ? <StatusPill status="maintenance" label="تحت حد التنبيه" /> : <StatusPill status="available" label="رصيد كافٍ" />),
    sortValue: (i) => (i.is_low ? 0 : 1),
    mobile: 'badge',
  },
]

const InventoryPageTabs = ['all', 'low'] as const

export function InventoryPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Item[]>([])
  const [loading, setLoading] = useState(true)
  const [openItem, setOpenItem] = useState(false)
  const [editingItem, setEditingItem] = useState<Item | null>(null)
  const [openBatch, setOpenBatch] = useState(false)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [chip, setChip] = useQueryTab({ allowed: InventoryPageTabs, defaultValue: 'all' })
  const [itemForm, setItemForm] = useState(emptyItemForm)
  const [batchForm, setBatchForm] = useState({ inventory_item_id: '', received_on: todayCivilIso(), quantity: '', unit_cost: '', supplier: '' })
  const [savingItem, setSavingItem] = useState(false)

  const lowCount = useMemo(() => rows.filter((i) => i.is_low).length, [rows])
  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      {
        id: 'low',
        label: 'تحت حد التنبيه',
        attention: lowCount > 0 ? ('warning' as const) : null,
        attentionLabel: `${lowCount} صنف تحت حد إعادة الطلب`,
      },
    ],
    [lowCount],
  )
  const filtered = useMemo(() => {
    const q = search.trim()
    return rows.filter((i) => {
      if (chip === 'low' && !i.is_low) return false
      if (!q) return true
      return `${i.name} ${i.code} ${i.category}`.includes(q)
    })
  }, [rows, chip, search])

  async function load(nextPage = page) {
    setLoading(true)
    try {
      const res = await api<{ data: Item[]; meta?: { current_page: number; last_page: number; total: number } }>(`/inventory?page=${nextPage}&per_page=50`)
      setRows(res.data)
      setMeta({
        current_page: res.meta?.current_page ?? nextPage,
        last_page: res.meta?.last_page ?? 1,
        total: res.meta?.total ?? res.data.length,
      })
      setPage(res.meta?.current_page ?? nextPage)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load(page) }, [page])

  function openCreateItem() {
    setEditingItem(null)
    setItemForm(emptyItemForm())
    setOpenItem(true)
  }

  function openEditItem(item: Item) {
    setEditingItem(item)
    setItemForm({
      code: item.code || '',
      name: item.name || '',
      category: item.category || 'زيوت',
      unit: item.unit || 'لتر',
      reorder_level: String(item.reorder_level ?? 5),
    })
    setOpenItem(true)
  }

  async function saveItem() {
    setSavingItem(true)
    try {
      const payload = {
        // Codes are system-owned — never send a manual/placeholder value on create.
        ...(editingItem ? {} : {}),
        name: itemForm.name,
        category: itemForm.category,
        unit: itemForm.unit,
        reorder_level: Number(itemForm.reorder_level),
      }
      if (editingItem) {
        await api(`/inventory/items/${editingItem.id}`, { method: 'PUT', json: payload })
        toast.success('تم تحديث الصنف.')
      } else {
        await api('/inventory/items', { method: 'POST', json: payload })
        toast.success('تم إضافة الصنف.')
      }
      setOpenItem(false)
      setEditingItem(null)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSavingItem(false)
    }
  }

  async function saveBatch() {
    const quantity = Number(batchForm.quantity)
    const unitCost = Number(batchForm.unit_cost)
    if (!batchForm.inventory_item_id) {
      toast.error('اختر الصنف.')
      return
    }
    if (!Number.isFinite(quantity) || quantity < 1) {
      toast.error('الكمية يجب أن تكون ١ على الأقل.')
      return
    }
    if (!Number.isFinite(unitCost) || unitCost < 1) {
      toast.error('سعر الوحدة مطلوب ويجب أن يكون أكبر من صفر.')
      return
    }
    try {
      await api('/inventory/batches', {
        method: 'POST',
        json: {
          ...batchForm,
          inventory_item_id: Number(batchForm.inventory_item_id),
          quantity,
          unit_cost: unitCost,
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
            <Button onClick={openCreateItem}>صنف جديد</Button>
          </div>
        }
        mobileFab
      />
      <Fab label="صنف جديد" onClick={openCreateItem} />
      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث بالصنف أو الكود…"
      />
      <DataTable
        label="أصناف المخزون"
        rows={filtered}
        columns={columns}
        rowKey={(i) => i.id}
        rowLabel={(i) => i.name}
        loading={loading}
        rowActions={(i) => [
          {
            id: 'edit',
            label: 'تعديل',
            icon: <IconEdit size={16} />,
            onSelect: () => openEditItem(i),
          },
          {
            id: 'batch',
            label: 'إدخال دفعة لهذا الصنف',
            icon: <IconBox size={16} />,
            onSelect: () => {
              setBatchForm((f) => ({ ...f, inventory_item_id: String(i.id) }))
              setOpenBatch(true)
            },
          },
        ]}
        emptyTitle="لا أصناف بعد"
        emptyDescription="أضف صنفاً ثم أدخل أول دفعة لبدء الصرف بالأقدم أولاً."
        serverPagination={{
          page: meta.current_page,
          lastPage: meta.last_page,
          total: meta.total,
          perPage: 50,
          onPageChange: setPage,
        }}
      />

      <Sheet
        open={openItem}
        title={editingItem ? 'تعديل صنف المخزون' : 'صنف مخزون'}
        onClose={() => { setOpenItem(false); setEditingItem(null) }}
        footer={
          <>
            <Button loading={savingItem} onClick={() => void saveItem()}>{editingItem ? 'حفظ التعديلات' : 'حفظ'}</Button>
            <Button variant="secondary" onClick={() => { setOpenItem(false); setEditingItem(null) }}>إلغاء</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <TextField
            label="الكود"
            value={editingItem ? itemForm.code : (itemForm.code || 'يُولَّد تلقائياً')}
            disabled
            hint={editingItem ? 'يُدار تلقائياً ولا يُعدَّل يدوياً' : 'يُولَّد تلقائياً عند الحفظ'}
            onChange={() => undefined}
          />
          <TextField label="الاسم" required value={itemForm.name} onChange={(e) => setItemForm({ ...itemForm, name: e.target.value })} />
          <TextField label="التصنيف" required value={itemForm.category} onChange={(e) => setItemForm({ ...itemForm, category: e.target.value })} />
          <TextField label="وحدة القياس" required value={itemForm.unit} onChange={(e) => setItemForm({ ...itemForm, unit: e.target.value })} />
          <NumberField label="حد تنبيه النقص" required value={itemForm.reorder_level} onChange={(e) => setItemForm({ ...itemForm, reorder_level: e.target.value })} />
        </div>
      </Sheet>

      <Sheet open={openBatch} title="دفعة إدخال" onClose={() => setOpenBatch(false)} footer={<><Button onClick={() => void saveBatch()}>حفظ</Button><Button variant="secondary" onClick={() => setOpenBatch(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField
            label="الصنف"
            required
            options={rows.map((i) => ({ value: String(i.id), label: `${i.code} · ${i.name}` }))}
            value={batchForm.inventory_item_id}
            onChange={(e) => setBatchForm({ ...batchForm, inventory_item_id: e.target.value })}
          />
          <TextField label="التاريخ" required type="date" value={batchForm.received_on} onChange={(e) => setBatchForm({ ...batchForm, received_on: e.target.value })} />
          <NumberField label="الكمية" required value={batchForm.quantity} onChange={(e) => setBatchForm({ ...batchForm, quantity: e.target.value })} />
          <MoneyField label="سعر الوحدة" required value={batchForm.unit_cost} onChange={(e) => setBatchForm({ ...batchForm, unit_cost: e.target.value })} />
          <TextField label="المورد (نص حر)" value={batchForm.supplier} onChange={(e) => setBatchForm({ ...batchForm, supplier: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
