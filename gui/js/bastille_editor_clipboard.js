document.addEventListener('DOMContentLoaded', function () {
    const pathDisplay = document.getElementById('ide-filepath-display');
    if (pathDisplay) {
        pathDisplay.addEventListener('click', function () {
            const originalText = this.getAttribute('data-orig') || this.textContent.trim();
            navigator.clipboard
                .writeText(originalText)
                .then(() => {
                    if (!this.getAttribute('data-orig')) {
                        this.setAttribute('data-orig', originalText);
                    }
                    this.textContent = 'Copied!';
                    setTimeout(() => {
                        this.textContent = originalText;
                        this.style.color = ''; // Vuelve al color original/hover
                    }, 800);
                })
                .catch((err) => {
                    console.error('Error al copiar: ', err);
                });
        });
    }
});
