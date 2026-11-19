/**
 * Options of the boards sorted by drag and drop (vue-draggable-plus) : on a touch screen a card moves after a
 * long press only, so that a swipe over the cards still scrolls the board and the page.
 */
export const TOUCH_DRAG = {
  delay: 250,
  delayOnTouchOnly: true,
  // A finger trembles : below these pixels the long press still counts
  touchStartThreshold: 6,
} as const
