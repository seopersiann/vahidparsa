'use strict';
// Configuration: only official addresses supplied by the site owner are used in HTML.
const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#main-nav');
function closeMenu() {
  navigation.classList.remove('is-open');
  menuButton.setAttribute('aria-expanded', 'false');
  menuButton.setAttribute('aria-label', 'باز کردن منو');
}
menuButton.addEventListener('click', () => {
  const open = menuButton.getAttribute('aria-expanded') !== 'true';
  navigation.classList.toggle('is-open', open);
  menuButton.setAttribute('aria-expanded', String(open));
  menuButton.setAttribute('aria-label', open ? 'بستن منو' : 'باز کردن منو');
});
navigation.addEventListener('click', event => { if (event.target.closest('a')) closeMenu(); });
document.addEventListener('click', event => { if (!event.target.closest('.site-header')) closeMenu(); });
document.addEventListener('keydown', event => { if (event.key === 'Escape') { const open = menuButton.getAttribute('aria-expanded') === 'true'; closeMenu(); if (open) menuButton.focus(); } });
window.matchMedia('(min-width: 851px)').addEventListener('change', closeMenu);
const header = document.querySelector('.site-header');
window.addEventListener('scroll', () => header.classList.toggle('scrolled', window.scrollY > 20), { passive: true });
const filters = [...document.querySelectorAll('[data-filter]')];
const contentCards = [...document.querySelectorAll('.content-card')];
function filterLessons(category) {
  let count = 0;
  contentCards.forEach(card => { card.hidden = category !== 'all' && card.dataset.category !== category; if (!card.hidden) count++; });
  filters.forEach(button => { const active = button.dataset.filter === category; button.classList.toggle('active', active); button.setAttribute('aria-pressed', String(active)); });
  document.querySelector('#filter-status').textContent = `${count.toLocaleString('fa-IR')} راهنمای آموزشی نمایش داده شد.`;
}
filters.forEach(button => button.addEventListener('click', () => filterLessons(button.dataset.filter)));
document.querySelectorAll('[data-category-link]').forEach(link => link.addEventListener('click', () => filterLessons(link.dataset.categoryLink)));
const dialog = document.querySelector('#lesson-dialog');
const lessonContent = document.querySelector('#lesson-content');
let lessonCache;
let lastTrigger;
let loading = false;
function element(tag, text) { const node = document.createElement(tag); node.textContent = text; return node; }
document.querySelectorAll('[data-lesson]').forEach(button => button.addEventListener('click', async () => {
  if (loading) return;
  lastTrigger = button;
  loading = true;
  button.setAttribute('aria-busy', 'true');
  try {
    if (!lessonCache) {
      const response = await fetch('assets/js/lessons.json');
      if (!response.ok) throw new Error('Content unavailable');
      lessonCache = await response.json();
    }
    const lesson = lessonCache[button.dataset.lesson];
    const title = element('h2', lesson.title);
    title.id = 'lesson-title';
    lessonContent.replaceChildren(title, element('p', lesson.intro));
    lesson.sections.forEach(([heading, text], index) => {
      lessonContent.append(element('h3', `${(index + 1).toLocaleString('fa-IR')}. ${heading}`), element('p', text));
    });
  } catch {
    const title = element('h2', 'راهنما فعلاً در دسترس نیست');
    title.id = 'lesson-title';
    lessonContent.replaceChildren(title, element('p', 'لطفاً دوباره امتحان کن یا آموزش‌های ویدیویی را در یوتیوب ببین.'));
  } finally {
    loading = false;
    button.removeAttribute('aria-busy');
    dialog.showModal();
    dialog.scrollTop = 0;
    document.body.classList.add('modal-open');
  }
}));
document.querySelector('.dialog-close').addEventListener('click', () => dialog.close());
dialog.addEventListener('click', event => { if (event.target === dialog) { const box = dialog.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close(); } });
dialog.addEventListener('close', () => { document.body.classList.remove('modal-open'); if (lastTrigger) lastTrigger.focus(); });
const aboutDetails = document.querySelector('#about-details');
document.querySelector('a[href="#about-details"]').addEventListener('click', () => { aboutDetails.open = true; });
