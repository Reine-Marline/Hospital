// Script principal pour le système de gestion hôpital

// Confirmer la suppression
function confirmDelete(message = 'Êtes-vous sûr de vouloir supprimer cet élément ?') {
    return confirm(message);
}

// Formater une date
function formatDate(date) {
    const options = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' };
    return new Date(date).toLocaleDateString('fr-FR', options);
}

// Afficher une notification
function showNotification(message, type = 'info', duration = 3000) {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type}`;
    notification.textContent = message;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.maxWidth = '400px';
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, duration);
}

// Valider un formulaire
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return false;
    
    const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
    let isValid = true;
    
    inputs.forEach(input => {
        if (!input.value.trim()) {
            input.style.borderColor = 'red';
            isValid = false;
        } else {
            input.style.borderColor = '';
        }
    });
    
    return isValid;
}

// Recherche en direct
function liveSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    
    if (!input || !table) return;
    
    input.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
}

// Exporter un tableau en CSV
function exportTableToCSV(tableId, filename = 'export.csv') {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        let csvRow = [];
        
        cols.forEach(col => {
            csvRow.push('"' + col.textContent.replace(/"/g, '""') + '"');
        });
        
        csv.push(csvRow.join(','));
    });
    
    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.href = URL.createObjectURL(csvFile);
    downloadLink.download = filename;
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// Imprimer un élément
function printElement(elementId) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const printWindow = window.open('', '', 'height=600, width=800');
    printWindow.document.write('<html><head><title>Imprimer</title></head><body>');
    printWindow.document.write(element.innerHTML);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

// Initialiser les tooltips
function initTooltips() {
    const tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(element => {
        element.addEventListener('mouseover', function() {
            const tooltipText = this.getAttribute('data-tooltip');
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = tooltipText;
            tooltip.style.position = 'absolute';
            tooltip.style.backgroundColor = '#333';
            tooltip.style.color = 'white';
            tooltip.style.padding = '5px 10px';
            tooltip.style.borderRadius = '4px';
            tooltip.style.fontSize = '12px';
            tooltip.style.zIndex = '1000';
            tooltip.style.whiteSpace = 'nowrap';
            
            document.body.appendChild(tooltip);
            
            const rect = this.getBoundingClientRect();
            tooltip.style.top = (rect.top - tooltip.offsetHeight - 5) + 'px';
            tooltip.style.left = (rect.left + rect.width / 2 - tooltip.offsetWidth / 2) + 'px';
            
            this.tooltip = tooltip;
        });
        
        element.addEventListener('mouseout', function() {
            if (this.tooltip) {
                this.tooltip.remove();
            }
        });
    });
}

// Charger dynamiquement du contenu
function loadContent(url, targetId) {
    const target = document.getElementById(targetId);
    if (!target) return;
    
    fetch(url)
        .then(response => response.text())
        .then(data => {
            target.innerHTML = data;
        })
        .catch(error => {
            console.error('Erreur:', error);
            showNotification('Erreur lors du chargement du contenu', 'error');
        });
}

// Soumettre un formulaire via AJAX
function submitFormAjax(formId, successCallback) {
    const form = document.getElementById(formId);
    if (!form) return;
    
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const action = this.getAttribute('action') || window.location.href;
        
        fetch(action, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            if (successCallback) {
                successCallback(data);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            showNotification('Erreur lors de la soumission du formulaire', 'error');
        });
    });
}

// Initialiser au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    initTooltips();

    const navToggle = document.querySelector('.nav-toggle');
    const primaryNavigation = document.getElementById('primary-navigation');
    if (navToggle && primaryNavigation) {
        const closeNavigation = () => {
            primaryNavigation.classList.remove('is-open');
            navToggle.setAttribute('aria-expanded', 'false');
            navToggle.setAttribute('aria-label', 'Ouvrir le menu');
        };

        navToggle.addEventListener('click', function() {
            const isOpen = primaryNavigation.classList.toggle('is-open');
            navToggle.setAttribute('aria-expanded', String(isOpen));
            navToggle.setAttribute('aria-label', isOpen ? 'Fermer le menu' : 'Ouvrir le menu');
        });

        primaryNavigation.querySelectorAll('.nav-group').forEach(group => {
            group.addEventListener('toggle', function() {
                if (!this.open) return;
                primaryNavigation.querySelectorAll('.nav-group').forEach(other => {
                    if (other !== this) other.open = false;
                });
            });
        });

        primaryNavigation.querySelectorAll('a').forEach(link => link.addEventListener('click', closeNavigation));
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                closeNavigation();
                primaryNavigation.querySelectorAll('.nav-group').forEach(group => { group.open = false; });
                if (navToggle.getClientRects().length > 0) navToggle.focus();
            }
        });
        document.addEventListener('click', event => {
            if (!primaryNavigation.contains(event.target) && !navToggle.contains(event.target)) {
                primaryNavigation.querySelectorAll('.nav-group').forEach(group => { group.open = false; });
            }
        });
    }
    
    // Ajouter des événements de validation aux formulaires
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const inputs = this.querySelectorAll('input[required], textarea[required], select[required]');
            let isValid = true;
            
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.style.borderColor = 'red';
                    isValid = false;
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                showNotification('Veuillez remplir tous les champs obligatoires', 'error');
            }
        });
    });
});

// Debounce pour les événements fréquents
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Vérifier la connexion réseau
function checkConnection() {
    return navigator.onLine;
}

// Afficher un message si la connexion est perdue
window.addEventListener('offline', function() {
    showNotification('Connexion perdue', 'error');
});

window.addEventListener('online', function() {
    showNotification('Connexion rétablie', 'success');
});
