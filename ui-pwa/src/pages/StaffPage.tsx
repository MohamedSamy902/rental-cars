import { useEffect, useMemo, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import {
  Button, Sheet, Fab, TextField, SelectField, NumberField, MoneyField, SwitchField,
  useToast, EmptyState, ErrorState, SkeletonBlock, StatusPill,
  IconPlus, IconShield, IconBadge, IconUsers, IconSearch,
} from '@/shared/ui'
import { IconEdit, IconMail, IconPhone } from '@/shared/ui/Icons'
import './StaffPage.css'

type Role = {
  id: number
  name: string
  label: string
  permissions_count: number
  permission_ids: number[]
  permission_keys: string[]
}

type PermissionItem = {
  id: number
  key: string
  label: string
  module: string
  sensitive: boolean
}

type PermissionGroup = {
  module: string
  label: string
  items: PermissionItem[]
}

type StaffUser = {
  id: number
  name: string
  email: string
  phone?: string | null
  role_id: number | null
  role: { id: number; name: string; label: string } | null
  employee_id?: number | null
  is_active: boolean
  session_minutes?: number | null
}

type Employee = {
  id: number
  name: string
  job_title?: string | null
  hired_on?: string | null
  base_salary: number
  status: string
}

type TabId = 'users' | 'permissions' | 'hr'

const ease = [0.22, 1, 0.36, 1] as const

const emptyUserForm = {
  name: '',
  email: '',
  phone: '',
  password: '',
  role_id: '',
  employee_id: '',
  is_active: true,
  session_minutes: '',
  create_employee: true,
  job_title: '',
  hired_on: '',
  base_salary: '',
}

const emptyEmployeeForm = {
  name: '',
  job_title: '',
  hired_on: '',
  base_salary: '',
  status: 'active',
}

export function StaffPage() {
  const toast = useToast()
  const [tab, setTab] = useState<TabId>('users')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [users, setUsers] = useState<StaffUser[]>([])
  const [summary, setSummary] = useState({ total: 0, active: 0, inactive: 0, roles: 0 })
  const [roles, setRoles] = useState<Role[]>([])
  const [permissionGroups, setPermissionGroups] = useState<PermissionGroup[]>([])
  const [employees, setEmployees] = useState<Employee[]>([])
  const [selectedRoleId, setSelectedRoleId] = useState<number | null>(null)
  const [draftPermIds, setDraftPermIds] = useState<number[]>([])
  const [permDirty, setPermDirty] = useState(false)
  const [savingPerms, setSavingPerms] = useState(false)
  const [userSheet, setUserSheet] = useState(false)
  const [editingUser, setEditingUser] = useState<StaffUser | null>(null)
  const [userForm, setUserForm] = useState(emptyUserForm)
  const [userErrors, setUserErrors] = useState<Record<string, string>>({})
  const [savingUser, setSavingUser] = useState(false)
  const [employeeSheet, setEmployeeSheet] = useState(false)
  const [employeeForm, setEmployeeForm] = useState(emptyEmployeeForm)
  const [savingEmployee, setSavingEmployee] = useState(false)
  const [roleSheet, setRoleSheet] = useState(false)
  const [roleForm, setRoleForm] = useState({ name: '', label: '' })
  const [savingRole, setSavingRole] = useState(false)

  const selectedRole = useMemo(
    () => roles.find((r) => r.id === selectedRoleId) || null,
    [roles, selectedRoleId],
  )

  async function loadUsers(q = search) {
    const qs = new URLSearchParams()
    if (q.trim()) qs.set('search', q.trim())
    const res = await api<{
      data: StaffUser[]
      summary: typeof summary
    }>(`/staff?${qs}`)
    setUsers(res.data)
    setSummary(res.summary)
  }

  async function loadRoles() {
    const res = await api<{ data: { roles: Role[]; permissions: PermissionGroup[] } }>('/roles')
    setRoles(res.data.roles)
    setPermissionGroups(res.data.permissions)
    setSelectedRoleId((prev) => {
      if (prev && res.data.roles.some((r) => r.id === prev)) return prev
      return res.data.roles[0]?.id ?? null
    })
  }

  async function loadEmployees() {
    const res = await api<{ data: Employee[] }>('/employees?per_page=100')
    setEmployees(res.data)
  }

  async function boot() {
    setLoading(true)
    setError('')
    try {
      await Promise.all([loadUsers(), loadRoles(), loadEmployees()])
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void boot()
  }, [])

  useEffect(() => {
    const t = window.setTimeout(() => {
      void loadUsers(search).catch((e) => toast.error((e as ApiError).message))
    }, 250)
    return () => window.clearTimeout(t)
  }, [search])

  useEffect(() => {
    if (!selectedRole) {
      setDraftPermIds([])
      setPermDirty(false)
      return
    }
    setDraftPermIds(selectedRole.permission_ids)
    setPermDirty(false)
  }, [selectedRoleId, selectedRole?.permission_ids.join(',')])

  function openCreateUser() {
    setEditingUser(null)
    setUserForm({
      ...emptyUserForm,
      role_id: roles[0] ? String(roles[0].id) : '',
      hired_on: new Date().toISOString().slice(0, 10),
    })
    setUserErrors({})
    setUserSheet(true)
  }

  function openEditUser(user: StaffUser) {
    setEditingUser(user)
    setUserForm({
      name: user.name,
      email: user.email,
      phone: user.phone || '',
      password: '',
      role_id: user.role_id ? String(user.role_id) : '',
      employee_id: user.employee_id ? String(user.employee_id) : '',
      is_active: user.is_active,
      session_minutes: user.session_minutes ? String(user.session_minutes) : '',
      create_employee: false,
      job_title: '',
      hired_on: '',
      base_salary: '',
    })
    setUserErrors({})
    setUserSheet(true)
  }

  async function saveUser() {
    setSavingUser(true)
    setUserErrors({})
    try {
      const payload: Record<string, unknown> = {
        name: userForm.name,
        email: userForm.email,
        phone: userForm.phone || null,
        role_id: Number(userForm.role_id),
        employee_id: userForm.employee_id ? Number(userForm.employee_id) : null,
        is_active: userForm.is_active,
        session_minutes: userForm.session_minutes ? Number(userForm.session_minutes) : null,
      }

      if (editingUser) {
        if (userForm.password) payload.password = userForm.password
        await api(`/staff/${editingUser.id}`, { method: 'PUT', json: payload })
        toast.success('تم تحديث الموظف.')
      } else {
        payload.password = userForm.password
        payload.create_employee = userForm.create_employee
        if (userForm.create_employee) {
          payload.job_title = userForm.job_title || null
          payload.hired_on = userForm.hired_on || null
          payload.base_salary = userForm.base_salary ? Number(userForm.base_salary) : 0
        }
        await api('/staff', { method: 'POST', json: payload })
        toast.success('تم إضافة الموظف.')
      }

      setUserSheet(false)
      await Promise.all([loadUsers(), loadEmployees()])
    } catch (e) {
      const err = e as ApiError
      const next: Record<string, string> = {}
      if (err.errors) for (const [k, v] of Object.entries(err.errors)) next[k] = v[0]
      setUserErrors(next)
      toast.error(err.message)
    } finally {
      setSavingUser(false)
    }
  }

  function togglePerm(id: number) {
    setDraftPermIds((prev) => {
      const next = prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
      setPermDirty(true)
      return next
    })
  }

  function toggleModule(group: PermissionGroup, enable: boolean) {
    const ids = group.items.map((i) => i.id)
    setDraftPermIds((prev) => {
      const without = prev.filter((id) => !ids.includes(id))
      const next = enable ? [...without, ...ids] : without
      setPermDirty(true)
      return next
    })
  }

  async function savePermissions() {
    if (!selectedRole) return
    setSavingPerms(true)
    try {
      const res = await api<{ data: Role }>(`/roles/${selectedRole.id}/permissions`, {
        method: 'PUT',
        json: { permission_ids: draftPermIds },
      })
      setRoles((prev) => prev.map((r) => (r.id === res.data.id ? { ...r, ...res.data } : r)))
      setPermDirty(false)
      toast.success('تم حفظ صلاحيات الدور.')
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSavingPerms(false)
    }
  }

  async function saveEmployee() {
    setSavingEmployee(true)
    try {
      await api('/employees', {
        method: 'POST',
        json: {
          name: employeeForm.name,
          job_title: employeeForm.job_title || null,
          hired_on: employeeForm.hired_on || null,
          base_salary: Number(employeeForm.base_salary || 0),
          status: employeeForm.status,
        },
      })
      toast.success('تم تسجيل الموظف.')
      setEmployeeSheet(false)
      setEmployeeForm(emptyEmployeeForm)
      await loadEmployees()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSavingEmployee(false)
    }
  }

  async function saveRole() {
    setSavingRole(true)
    try {
      const res = await api<{ data: Role }>('/roles', {
        method: 'POST',
        json: roleForm,
      })
      setRoles((prev) => [...prev, res.data])
      setSelectedRoleId(res.data.id)
      setRoleSheet(false)
      setRoleForm({ name: '', label: '' })
      toast.success('تم إنشاء الدور.')
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSavingRole(false)
    }
  }

  if (loading) {
    return (
      <div className="st">
        <SkeletonBlock rows={8} />
      </div>
    )
  }

  if (error) {
    return (
      <div className="st">
        <ErrorState title="تعذّر تحميل الموظفين" description={error} />
      </div>
    )
  }

  return (
    <div className="st">
      <motion.header
        className="st-hero"
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35, ease }}
      >
        <div>
          <p className="st-hero__eyebrow">الموارد والصلاحيات</p>
          <h1>الموظفون والصلاحيات</h1>
          <p>
            إدارة حسابات الدخول والأدوار وفق أقل صلاحية لازمة — الصلاحيات الحساسة لا تُمنح ضمناً.
          </p>
        </div>
        <div className="st-hero__actions st-hero__actions--desk">
          {tab === 'permissions' ? (
            <Button variant="secondary" onClick={() => setRoleSheet(true)}>دور جديد</Button>
          ) : null}
          {tab === 'hr' ? (
            <button type="button" className="st-cta" onClick={() => setEmployeeSheet(true)}>
              <IconPlus size={16} />
              موظف موارد بشرية
            </button>
          ) : (
            <button type="button" className="st-cta" onClick={openCreateUser}>
              <IconPlus size={16} />
              موظف جديد
            </button>
          )}
        </div>
      </motion.header>

      <Fab
        label={tab === 'hr' ? 'موظف موارد بشرية' : tab === 'permissions' ? 'دور جديد' : 'موظف جديد'}
        onClick={() => {
          if (tab === 'hr') setEmployeeSheet(true)
          else if (tab === 'permissions') setRoleSheet(true)
          else openCreateUser()
        }}
      />

      <section className="st-kpis" aria-label="ملخص الموظفين">
        {[
          { label: 'إجمالي الحسابات', value: summary.total, icon: <IconUsers size={16} />, tone: 'brand' },
          { label: 'نشطون', value: summary.active, icon: <IconBadge size={16} />, tone: 'ok' },
          { label: 'موقوفون', value: summary.inactive, icon: <IconBadge size={16} />, tone: 'warn' },
          { label: 'الأدوار', value: summary.roles, icon: <IconShield size={16} />, tone: 'brand' },
        ].map((k, i) => (
          <motion.article
            key={k.label}
            className={`st-kpi tone-${k.tone}`}
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.05 * i, duration: 0.35, ease }}
          >
            <div className="st-kpi__top">
              <span>{k.label}</span>
              <span className="st-kpi__ico">{k.icon}</span>
            </div>
            <strong className="tabular-nums">{formatNumber(k.value)}</strong>
          </motion.article>
        ))}
      </section>

      <div className="st-tabs" role="tablist" aria-label="أقسام الصفحة">
        {([
          { id: 'users', label: 'حسابات الدخول' },
          { id: 'permissions', label: 'الأدوار والصلاحيات' },
          { id: 'hr', label: 'سجل الموارد البشرية' },
        ] as const).map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            aria-selected={tab === t.id}
            className={`st-tabs__btn ${tab === t.id ? 'is-active' : ''}`}
            onClick={() => setTab(t.id)}
          >
            {t.label}
          </button>
        ))}
      </div>

      <AnimatePresence mode="wait">
        {tab === 'users' ? (
          <motion.section
            key="users"
            className="st-panel"
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -6 }}
            transition={{ duration: 0.28, ease }}
          >
            <div className="st-panel__head">
              <div>
                <h2>حسابات الموظفين</h2>
                <p>كل حساب مربوط بدور صريح ويمكن ربطه بسجل موظف اختياريًا.</p>
              </div>
              <label className="st-search">
                <IconSearch size={16} />
                <input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="بحث بالاسم أو البريد أو الهاتف…"
                />
              </label>
            </div>

            {users.length === 0 ? <EmptyState title="لا يوجد موظفون مطابقون" /> : null}

            <div className="st-user-grid">
              {users.map((u, i) => (
                <motion.article
                  key={u.id}
                  className="st-user"
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: Math.min(i, 8) * 0.03, duration: 0.3, ease }}
                >
                  <header className="st-user__head">
                    <span className="st-user__avatar" aria-hidden>{u.name.slice(0, 1)}</span>
                    <div className="st-user__id">
                      <strong>{u.name}</strong>
                      <span className="st-user__role">
                        <IconShield size={13} />
                        {u.role?.label || 'بدون دور'}
                      </span>
                    </div>
                    <StatusPill
                      status={u.is_active ? 'active' : 'cancelled'}
                      label={u.is_active ? 'نشط' : 'موقوف'}
                    />
                  </header>
                  <dl className="st-user__contact">
                    <div>
                      <dt><IconMail size={14} /><span>البريد</span></dt>
                      <dd dir="ltr">{u.email}</dd>
                    </div>
                    <div>
                      <dt><IconPhone size={14} /><span>الهاتف</span></dt>
                      <dd dir="ltr" className="tabular-nums">{u.phone || '—'}</dd>
                    </div>
                  </dl>
                  <footer className="st-user__foot">
                    <Button size="sm" variant="secondary" iconStart={<IconEdit size={15} />} onClick={() => openEditUser(u)}>
                      تعديل الحساب
                    </Button>
                  </footer>
                </motion.article>
              ))}
            </div>
          </motion.section>
        ) : null}

        {tab === 'permissions' ? (
          <motion.section
            key="permissions"
            className="st-perm"
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -6 }}
            transition={{ duration: 0.28, ease }}
          >
            <aside className="st-roles">
              <div className="st-roles__head">
                <h2>الأدوار</h2>
                <p>اختر دوراً لتعديل مصفوفة صلاحياته.</p>
              </div>
              <div className="st-roles__list">
                {roles.map((role) => (
                  <button
                    key={role.id}
                    type="button"
                    className={`st-role ${selectedRoleId === role.id ? 'is-active' : ''}`}
                    onClick={() => setSelectedRoleId(role.id)}
                  >
                    <strong>{role.label}</strong>
                    <span className="tabular-nums">{formatNumber(role.permissions_count)} صلاحية</span>
                  </button>
                ))}
              </div>
            </aside>

            <div className="st-matrix">
              <div className="st-matrix__head">
                <div>
                  <h2>{selectedRole?.label || 'اختر دوراً'}</h2>
                  <p>
                    {permDirty
                      ? 'توجد تغييرات غير محفوظة على هذا الدور.'
                      : 'عرض · إضافة · تعديل · اعتماد · وطباعة/تصدير حسب الوحدة.'}
                  </p>
                </div>
                <Button disabled={!permDirty || !selectedRole} loading={savingPerms} onClick={() => void savePermissions()}>
                  حفظ الصلاحيات
                </Button>
              </div>

              {!selectedRole ? <EmptyState title="لا يوجد دور محدد" /> : null}

              <div className="st-matrix__body">
                {permissionGroups.map((group) => {
                  const enabledCount = group.items.filter((i) => draftPermIds.includes(i.id)).length
                  const allOn = enabledCount === group.items.length && group.items.length > 0
                  return (
                    <article key={group.module} className="st-module">
                      <header className="st-module__head">
                        <div>
                          <strong>{group.label}</strong>
                          <span className="tabular-nums">
                            {formatNumber(enabledCount)} / {formatNumber(group.items.length)}
                          </span>
                        </div>
                        <button
                          type="button"
                          className="st-module__all"
                          onClick={() => toggleModule(group, !allOn)}
                        >
                          {allOn ? 'إلغاء الكل' : 'تفعيل الكل'}
                        </button>
                      </header>
                      <div className="st-module__grid">
                        {group.items.map((item) => (
                          <label
                            key={item.id}
                            className={`st-check ${draftPermIds.includes(item.id) ? 'is-on' : ''} ${item.sensitive ? 'is-sensitive' : ''}`}
                          >
                            <input
                              type="checkbox"
                              checked={draftPermIds.includes(item.id)}
                              onChange={() => togglePerm(item.id)}
                            />
                            <span>
                              {item.label.split(' · ').slice(1).join(' · ') || item.label}
                              {item.sensitive ? <em>حسّاسة</em> : null}
                            </span>
                          </label>
                        ))}
                      </div>
                    </article>
                  )
                })}
              </div>
            </div>
          </motion.section>
        ) : null}

        {tab === 'hr' ? (
          <motion.section
            key="hr"
            className="st-panel"
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -6 }}
            transition={{ duration: 0.28, ease }}
          >
            <div className="st-panel__head">
              <div>
                <h2>سجل الموارد البشرية</h2>
                <p>بيانات الموظف التشغيلية للرواتب والعهدة — منفصلة عن حساب الدخول.</p>
              </div>
            </div>

            {employees.length === 0 ? <EmptyState title="لا يوجد موظفون في السجل" /> : null}

            <div className="st-hr-list">
              {employees.map((emp) => (
                <article key={emp.id} className="st-hr">
                  <div>
                    <strong>{emp.name}</strong>
                    <p>{emp.job_title || 'بدون مسمى'} · تعيين {emp.hired_on || '—'}</p>
                  </div>
                  <div className="st-hr__end">
                    <span className="tabular-nums">{formatMoney(emp.base_salary)}</span>
                    <StatusPill
                      status={emp.status === 'active' ? 'active' : 'completed'}
                      label={emp.status === 'active' ? 'على رأس العمل' : 'منتهٍ'}
                    />
                  </div>
                </article>
              ))}
            </div>
          </motion.section>
        ) : null}
      </AnimatePresence>

      <Sheet
        open={userSheet}
        title={editingUser ? 'تعديل موظف' : 'إضافة موظف'}
        onClose={() => setUserSheet(false)}
        footer={(
          <>
            <Button loading={savingUser} onClick={() => void saveUser()}>حفظ</Button>
            <Button variant="secondary" onClick={() => setUserSheet(false)}>إلغاء</Button>
          </>
        )}
      >
        <div className="rpm-form-grid">
          <TextField label="الاسم" required value={userForm.name} error={userErrors.name} onChange={(e) => setUserForm({ ...userForm, name: e.target.value })} />
          <TextField label="البريد الإلكتروني" required type="email" value={userForm.email} error={userErrors.email} onChange={(e) => setUserForm({ ...userForm, email: e.target.value })} />
          <TextField label="الهاتف" value={userForm.phone} error={userErrors.phone} onChange={(e) => setUserForm({ ...userForm, phone: e.target.value })} />
          <TextField
            label={editingUser ? 'كلمة مرور جديدة' : 'كلمة المرور'}
            required={!editingUser}
            type="password"
            value={userForm.password}
            error={userErrors.password}
            hint={editingUser ? 'اتركها فارغة إن لم ترد التغيير' : '٨ أحرف على الأقل'}
            onChange={(e) => setUserForm({ ...userForm, password: e.target.value })}
          />
          <SelectField
            label="الدور"
            required
            options={roles.map((r) => ({ value: String(r.id), label: r.label }))}
            value={userForm.role_id}
            error={userErrors.role_id}
            onChange={(e) => setUserForm({ ...userForm, role_id: e.target.value })}
          />
          <SelectField
            label="ربط بسجل موظف"
            options={[
              { value: '', label: 'بدون ربط' },
              ...employees.map((e) => ({ value: String(e.id), label: `${e.name}${e.job_title ? ` · ${e.job_title}` : ''}` })),
            ]}
            value={userForm.employee_id}
            error={userErrors.employee_id}
            onChange={(e) => setUserForm({ ...userForm, employee_id: e.target.value, create_employee: false })}
          />
          <NumberField
            label="مدة الجلسة بالدقائق"
            value={userForm.session_minutes}
            error={userErrors.session_minutes}
            hint="فارغ = من إعدادات النظام"
            onChange={(e) => setUserForm({ ...userForm, session_minutes: e.target.value })}
          />
          <SwitchField
            label="الحساب نشط"
            checked={userForm.is_active}
            onChange={(next) => setUserForm({ ...userForm, is_active: next })}
          />

          {!editingUser ? (
            <>
              <SwitchField
                label="إنشاء سجل موارد بشرية مع الحساب"
                checked={userForm.create_employee}
                onChange={(next) => setUserForm({ ...userForm, create_employee: next })}
              />
              {userForm.create_employee ? (
                <>
                  <TextField label="المسمى الوظيفي" value={userForm.job_title} onChange={(e) => setUserForm({ ...userForm, job_title: e.target.value })} />
                  <TextField label="تاريخ التعيين" type="date" value={userForm.hired_on} onChange={(e) => setUserForm({ ...userForm, hired_on: e.target.value })} />
                  <MoneyField label="الراتب الأساسي" value={userForm.base_salary} onChange={(e) => setUserForm({ ...userForm, base_salary: e.target.value })} />
                </>
              ) : null}
            </>
          ) : null}
        </div>
      </Sheet>

      <Sheet
        open={employeeSheet}
        title="إضافة موظف موارد بشرية"
        onClose={() => setEmployeeSheet(false)}
        footer={(
          <>
            <Button loading={savingEmployee} onClick={() => void saveEmployee()}>حفظ</Button>
            <Button variant="secondary" onClick={() => setEmployeeSheet(false)}>إلغاء</Button>
          </>
        )}
      >
        <div className="rpm-form-grid">
          <TextField label="الاسم" required value={employeeForm.name} onChange={(e) => setEmployeeForm({ ...employeeForm, name: e.target.value })} />
          <TextField label="المسمى الوظيفي" value={employeeForm.job_title} onChange={(e) => setEmployeeForm({ ...employeeForm, job_title: e.target.value })} />
          <TextField label="تاريخ التعيين" type="date" value={employeeForm.hired_on} onChange={(e) => setEmployeeForm({ ...employeeForm, hired_on: e.target.value })} />
          <MoneyField label="الراتب الأساسي" required value={employeeForm.base_salary} onChange={(e) => setEmployeeForm({ ...employeeForm, base_salary: e.target.value })} />
          <SelectField
            label="الحالة"
            options={[
              { value: 'active', label: 'على رأس العمل' },
              { value: 'ended', label: 'منتهٍ' },
            ]}
            value={employeeForm.status}
            onChange={(e) => setEmployeeForm({ ...employeeForm, status: e.target.value })}
          />
        </div>
      </Sheet>

      <Sheet
        open={roleSheet}
        title="دور جديد"
        onClose={() => setRoleSheet(false)}
        footer={(
          <>
            <Button loading={savingRole} onClick={() => void saveRole()}>إنشاء</Button>
            <Button variant="secondary" onClick={() => setRoleSheet(false)}>إلغاء</Button>
          </>
        )}
      >
        <div className="rpm-form-grid">
          <TextField
            label="اسم الدور"
            required
            value={roleForm.label}
            onChange={(e) => setRoleForm({ ...roleForm, label: e.target.value })}
          />
          <TextField
            label="المفتاح الإنجليزي"
            required
            value={roleForm.name}
            hint="مثل ops_manager"
            onChange={(e) => setRoleForm({ ...roleForm, name: e.target.value.trim().toLowerCase() })}
          />
        </div>
      </Sheet>
    </div>
  )
}
