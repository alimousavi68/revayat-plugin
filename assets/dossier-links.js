(function ($) {
    'use strict';
    function init() {
        const root = document.querySelector('.rv-dossier-editor');
        if (!root || root.dataset.ready) return;
        root.dataset.ready = '1';
        const add = (kind, item) => {
            const list = root.querySelector('.rv-selected-' + kind);
            if (list.querySelector('li[data-id="' + Number(item.id) + '"]')) return;
            if (list.children.length >= 100) { root.querySelector('.rv-search-message').textContent = 'حداکثر ۱۰۰ مورد قابل انتخاب است.'; return; }
            const row = document.createElement('li');
            row.dataset.id = Number(item.id);
            const title = document.createElement('span');
            title.textContent = item.title || 'بدون عنوان';
            const remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'button-link-delete rv-remove-link'; remove.textContent = 'حذف اتصال'; remove.setAttribute('aria-label', 'حذف اتصال ' + title.textContent);
            const field = document.createElement('input');
            field.type = 'hidden'; field.name = 'revayat_dossier_' + kind + '[]'; field.value = Number(item.id);
            row.append(title, remove, field); list.append(row);
        };
        root.addEventListener('click', event => {
            const remove = event.target.closest('.rv-remove-link');
            if (remove) remove.closest('li').remove();
        });
        root.querySelector('.rv-add-documents').addEventListener('click', () => {
            const picker = wp.media({title: 'انتخاب اسناد عمومی پرونده', button: {text: 'اتصال به پرونده'}, multiple: true});
            picker.on('select', () => {
                const ids = picker.state().get('selection').map(model => model.id);
                const message = root.querySelector('.rv-search-message');
                message.textContent = 'در حال بررسی اسناد…';
                $.post(ajaxurl, {action: 'revayat_validate_dossier_documents', nonce: root.dataset.searchNonce, post_id: root.dataset.postId, ids})
                    .done(response => {
                        if (!response.success) { message.textContent = response.data?.message || 'اسناد بررسی نشدند.'; return; }
                        response.data.items.forEach(item => add('documents', item));
                        message.textContent = response.data.rejected ? 'بعضی موارد به‌علت نوع فایل نامعتبر، فایل مفقود یا وابستگی به محتوای غیرعمومی اضافه نشدند.' : 'اسناد انتخاب شدند؛ پرونده را ذخیره کنید.';
                    }).fail(() => { message.textContent = 'بررسی اسناد انجام نشد؛ دوباره تلاش کنید.'; });
            });
            picker.open();
        });
        let sequence = 0;
        const search = () => {
            const current = ++sequence;
            const message = root.querySelector('.rv-search-message');
            message.textContent = 'در حال جستجو…';
            $.post(ajaxurl, {action: 'revayat_search_dossier_content', nonce: root.dataset.searchNonce, post_id: root.dataset.postId, query: root.querySelector('#rv-dossier-search').value})
                .done(response => {
                    if (current !== sequence) return;
                    const list = root.querySelector('.rv-search-results'); list.replaceChildren();
                    if (!response.success) { message.textContent = response.data?.message || 'جستجو انجام نشد.'; return; }
                    response.data.forEach(item => {
                        const row = document.createElement('li');
                        const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'افزودن: ' + item.title + ' (' + item.label + ')';
                        button.addEventListener('click', () => { add('content', item); button.disabled = true; }); row.append(button); list.append(row);
                    });
                    message.textContent = response.data.length ? 'نتایج منتشرشده؛ حداکثر ۲۰ مورد. برای نتیجه دقیق‌تر عبارت را محدود کنید.' : 'مطلب منتشرشده‌ای یافت نشد.';
                }).fail(() => { if (current === sequence) message.textContent = 'خطا در جستجو؛ دوباره تلاش کنید.'; });
        };
        root.querySelector('.rv-search-content').addEventListener('click', search);
        root.querySelector('#rv-dossier-search').addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); search(); } });
    }
    $(init);
})(jQuery);
