/**
 * The toast store now lives in @gratora/ui. Re-export it so all call sites share
 * the SAME singleton instance as @gratora/ui's <Toaster/> (importing two copies
 * would split the store and silently drop toasts).
 */
export { notify, subscribe, dismiss } from '@gratora/ui/utils/notify';
export { default } from '@gratora/ui/utils/notify';
