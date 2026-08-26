/**
 * The toast store now lives in @giveflow/ui. Re-export it so all call sites share
 * the SAME singleton instance as @giveflow/ui's <Toaster/> (importing two copies
 * would split the store and silently drop toasts).
 */
export { notify, subscribe, dismiss } from '@giveflow/ui/utils/notify';
export { default } from '@giveflow/ui/utils/notify';
