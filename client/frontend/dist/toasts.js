(function () {
  'use strict';

  const MAX_TIMEOUT = 2147483647;

  function initialiseToast(toast) {
    let remaining = Number(toast.dataset.autohide) * 1000;
    const autoHide = remaining > 0;
    let startedAt = 0;
    let timer = null;
    let previousFocus = null;
    const paused = new Set();
    const close = toast.querySelector('.dt-toast__close');

    function schedule() {
      if (!autoHide || paused.size > 0 || timer !== null) return;
      if (remaining <= 0) {
        dismiss();
        return;
      }
      startedAt = performance.now();
      timer = setTimeout(() => {
        remaining -= performance.now() - startedAt;
        timer = null;
        if (remaining <= 0) dismiss();
        else schedule();
      }, Math.min(remaining, MAX_TIMEOUT));
    }

    function pause(reason) {
      paused.add(reason);
      if (timer !== null) {
        clearTimeout(timer);
        remaining = Math.max(0, remaining - (performance.now() - startedAt));
        timer = null;
      }
    }

    function resume(reason) {
      paused.delete(reason);
      schedule();
    }

    function visibilityChanged() {
      if (document.hidden) pause('hidden');
      else resume('hidden');
    }

    function dismiss() {
      if (timer !== null) clearTimeout(timer);
      document.removeEventListener('visibilitychange', visibilityChanged);
      const region = toast.parentElement;
      const overlay = region.parentElement;
      const hadFocus = toast.contains(document.activeElement);
      toast.remove();
      if (!region.querySelector('.dt-toast')) region.remove();
      if (hadFocus) {
        const target = previousFocus?.isConnected ? previousFocus : overlay.querySelector('.dt-toast');
        target?.focus({ preventScroll: true });
      }
      if (!overlay.querySelector('.dt-toast')) overlay.remove();
    }

    if (close) {
      close.hidden = false;
      close.addEventListener('click', dismiss);
    }
    toast.addEventListener('mouseenter', () => pause('hover'));
    toast.addEventListener('mouseleave', () => resume('hover'));
    toast.addEventListener('focusin', (event) => {
      if (!toast.contains(event.relatedTarget)) previousFocus = event.relatedTarget;
      pause('focus');
    });
    toast.addEventListener('focusout', (event) => {
      if (!toast.contains(event.relatedTarget)) resume('focus');
    });
    document.addEventListener('visibilitychange', visibilityChanged);
    visibilityChanged();
  }

  function initialise() {
    const overlay = document.getElementById('developer-tools-toasts');
    if (!overlay || overlay.dataset.initialised) return;
    overlay.dataset.initialised = 'true';
    overlay.querySelectorAll('.dt-toast').forEach(initialiseToast);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialise, { once: true });
  } else {
    initialise();
  }

})();
