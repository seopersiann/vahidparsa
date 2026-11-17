const couponStatus = document.querySelector('#coupon-status');

if (couponStatus && navigator.clipboard?.writeText) {
  document.querySelectorAll('[data-copy-code]').forEach((button) => {
    const code = button.parentElement.querySelector('code').textContent;
    button.hidden = false;
    button.addEventListener('click', async () => {
      let result;
      try {
        await navigator.clipboard.writeText(code);
        result = `کد ${code} کپی شد.`;
      } catch {
        result = 'کپی خودکار در دسترس نبود. کد را انتخاب کن و دستی کپی کن.';
      }
      couponStatus.textContent = result;
    });
  });
}
