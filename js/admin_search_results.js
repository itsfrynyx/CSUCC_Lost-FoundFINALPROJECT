function renderAdminSearchResults(container, data) {
    const categories = [
        ['orders', 'Orders', 'admin_manage_orders.php'],
        ['customers', 'Customers', 'admin_manage_customers.php'],
        ['books', 'Books', 'admin_manage_books.php']
    ];
    const content = document.createDocumentFragment();
    categories.forEach(([key, label, page]) => {
        const entries = Array.isArray(data[key]) ? data[key].filter(item => /^[1-9][0-9]*$/.test(String(item?.id))) : [];
        if (entries.length === 0) return;
        const heading = document.createElement('div');
        heading.className = 'search-category';
        heading.textContent = label;
        content.append(heading);
        entries.forEach(item => {
            const link = document.createElement('a');
            link.className = 'search-item';
            link.href = page + '?id=' + encodeURIComponent(item.id);
            const title = document.createElement('div');
            title.className = 'search-item-title';
            title.textContent = item.title ?? '';
            const meta = document.createElement('div');
            meta.className = 'search-item-meta';
            meta.textContent = item.meta ?? '';
            link.append(title, meta);
            content.append(link);
        });
    });
    if (!content.hasChildNodes()) {
        const empty = document.createElement('div');
        empty.className = 'search-no-results';
        empty.textContent = 'No results found';
        content.append(empty);
    }
    container.replaceChildren(content);
    container.classList.add('show');
}
