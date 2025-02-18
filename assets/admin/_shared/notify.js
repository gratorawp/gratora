/**
 * The toast store now lives in @dono/ui. Re-export it so all call sites share
 * the SAME singleton instance as @dono/ui's <Toaster/> (importing two copies
 * would split the store and silently drop toasts).
 */
export { notify, subscribe, dismiss } from '@dono/ui/utils/notify';
export { default } from '@dono/ui/utils/notify';
