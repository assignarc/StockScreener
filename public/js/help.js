function filterHelpCategory(category) {
    const buttons = document.querySelectorAll('.help-pill-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    if (window.event && window.event.currentTarget) {
        window.event.currentTarget.classList.add('active');
    }

    const cards = document.querySelectorAll('.help-card');
    cards.forEach(card => {
        if (category === 'all') {
            card.style.display = 'flex';
        } else {
            const cardCat = card.getAttribute('data-category') || '';
            if (cardCat.includes(category)) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        }
    });
}
