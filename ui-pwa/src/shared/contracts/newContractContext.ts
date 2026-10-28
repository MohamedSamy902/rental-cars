import { createContext, useContext } from 'react'

export type NewContractApi = {
  /** Opens the create-contract sheet over the current page (no navigation) */
  openNewContract: () => void
  /** Bumps after every successful create so list screens can reload */
  createdVersion: number
}

export const NewContractContext = createContext<NewContractApi | null>(null)

export function useNewContract() {
  const ctx = useContext(NewContractContext)
  if (!ctx) throw new Error('useNewContract must be used within NewContractProvider')
  return ctx
}
