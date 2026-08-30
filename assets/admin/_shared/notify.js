/**
 * The toast store now lives in @fundkit/ui. Re-export it so all call sites share
 * the SAME singleton instance as @fundkit/ui's <Toaster/> (importing two copies
 * would split the store and silently drop toasts).
 */
export { notify, subscribe, dismiss } from '@fundkit/ui/utils/notify';
export { default } from '@fundkit/ui/utils/notify';
