/** Location state for list → detail so «رجوع» can POP and keep filters/scroll. */
export type DetailNavState = {
  from: string
}

export function detailNavState(pathname: string, search = ''): DetailNavState {
  return { from: `${pathname}${search}` }
}
