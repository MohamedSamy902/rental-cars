import { AttentionDot, type AttentionTone } from './AttentionDot'
import './FilterBar.css'
import './AttentionDot.css'

type Chip = {
  id: string
  label: string
  attention?: AttentionTone | null
  attentionLabel?: string
}

type Props = {
  search: string
  onSearchChange: (value: string) => void
  chips: Chip[]
  activeChip: string
  onChipChange: (id: string) => void
  placeholder?: string
}

export function FilterBar({
  search,
  onSearchChange,
  chips,
  activeChip,
  onChipChange,
  placeholder = 'بحث…',
}: Props) {
  return (
    <div className="rpm-filters">
      <input
        className="rpm-filters__search"
        type="search"
        value={search}
        onChange={(e) => onSearchChange(e.target.value)}
        placeholder={placeholder}
        aria-label="بحث"
      />
      <div className="rpm-filters__chips" role="tablist" aria-label="فلاتر">
        {chips.map((chip) => (
          <button
            key={chip.id}
            type="button"
            role="tab"
            aria-selected={activeChip === chip.id}
            className={`rpm-chip ${activeChip === chip.id ? 'is-active' : ''}`}
            onClick={() => onChipChange(chip.id)}
          >
            <span className="rpm-attention-inline">
              {chip.label}
              {chip.attention ? (
                <AttentionDot
                  tone={chip.attention}
                  placement="inline"
                  ping={chip.attention === 'critical'}
                  label={chip.attentionLabel}
                />
              ) : null}
            </span>
          </button>
        ))}
      </div>
    </div>
  )
}
