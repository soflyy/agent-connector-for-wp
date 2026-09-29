import React from 'react'

// Brand marks the bundled react-icons doesn't include, as inline SVG. Paths
// from LobeHub's icon set (@lobehub/icons, MIT). They take the same props as
// react-icons (size, style) and draw in currentColor.
function brandIcon(d) {
  return function BrandIcon({ size = 24, ...props }) {
    return (
      <svg viewBox="0 0 24 24" width={size} height={size} fill="currentColor" fillRule="evenodd" aria-hidden="true" {...props}>
        <path d={d} />
      </svg>
    )
  }
}

export const CursorIcon = brandIcon('M22.106 5.68L12.5.135a.998.998 0 00-.998 0L1.893 5.68a.84.84 0 00-.419.726v11.186c0 .3.16.577.42.727l9.607 5.547a.999.999 0 00.998 0l9.608-5.547a.84.84 0 00.42-.727V6.407a.84.84 0 00-.42-.726zm-.603 1.176L12.228 22.92c-.063.108-.228.064-.228-.061V12.34a.59.59 0 00-.295-.51l-9.11-5.26c-.107-.062-.063-.228.062-.228h18.55c.264 0 .428.286.296.514z')

export const AntigravityIcon = brandIcon('M21.751 22.607c1.34 1.005 3.35.335 1.508-1.508C17.73 15.74 18.904 1 12.037 1 5.17 1 6.342 15.74.815 21.1c-2.01 2.009.167 2.511 1.507 1.506 5.192-3.517 4.857-9.714 9.715-9.714 4.857 0 4.522 6.197 9.714 9.715z')
