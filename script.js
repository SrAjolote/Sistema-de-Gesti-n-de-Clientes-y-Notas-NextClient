// Funciones JavaScript para el Sistema de Gestión

// Configuración global
const CONFIG = {
    ajaxTimeout: 10000,
    confirmMessages: {
        delete: '¿Está seguro de que desea eliminar este elemento?',
        deactivate: '¿Está seguro de que desea desactivar este elemento?',
        activate: '¿Está seguro de que desea activar este elemento?'
    }
};

// Utilidades
const Utils = {
    // Mostrar/ocultar loading
    showLoading: function(element) {
        if (element) {
            element.disabled = true;
            const originalText = element.textContent;
            element.setAttribute('data-original-text', originalText);
            element.innerHTML = '<span class="loading"></span> Procesando...';
        }
    },
    
    hideLoading: function(element) {
        if (element) {
            element.disabled = false;
            const originalText = element.getAttribute('data-original-text');
            if (originalText) {
                element.textContent = originalText;
                element.removeAttribute('data-original-text');
            }
        }
    },
    
    // Mostrar notificaciones
    showNotification: function(message, type = 'info', duration = 5000) {
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 z-50 p-4 rounded-md shadow-lg max-w-sm fade-in`;
        
        switch (type) {
            case 'success':
                notification.className += ' bg-green-100 border border-green-400 text-green-700';
                break;
            case 'error':
                notification.className += ' bg-red-100 border border-red-400 text-red-700';
                break;
            case 'warning':
                notification.className += ' bg-yellow-100 border border-yellow-400 text-yellow-700';
                break;
            default:
                notification.className += ' bg-blue-100 border border-blue-400 text-blue-700';
        }
        
        notification.innerHTML = `
            <div class="flex justify-between items-center">
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-2 text-gray-500 hover:text-gray-700">
                    ✕
                </button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        if (duration > 0) {
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, duration);
        }
    },
    
    // Formatear números
    formatNumber: function(number) {
        return new Intl.NumberFormat('es-MX').format(number);
    },
    
    // Formatear moneda
    formatCurrency: function(amount) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN'
        }).format(amount);
    },
    
    // Validar email
    validateEmail: function(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    },
    
    // Validar teléfono
    validatePhone: function(phone) {
        const re = /^[\d\s\-\(\)\+]+$/;
        return re.test(phone) && phone.replace(/\D/g, '').length >= 10;
    },
    
    // Debounce function
    debounce: function(func, wait) {
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
};

// Manejo de formularios
const FormHandler = {
    // Validar formulario
    validateForm: function(form) {
        let isValid = true;
        const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
        
        inputs.forEach(input => {
            if (!input.value.trim()) {
                this.showFieldError(input, 'Este campo es obligatorio');
                isValid = false;
            } else {
                this.clearFieldError(input);
                
                // Validaciones específicas
                if (input.type === 'email' && !Utils.validateEmail(input.value)) {
                    this.showFieldError(input, 'Email inválido');
                    isValid = false;
                } else if (input.type === 'tel' && !Utils.validatePhone(input.value)) {
                    this.showFieldError(input, 'Teléfono inválido');
                    isValid = false;
                }
            }
        });
        
        return isValid;
    },
    
    // Mostrar error en campo
    showFieldError: function(field, message) {
        field.classList.add('is-invalid');
        
        let errorElement = field.parentElement.querySelector('.invalid-feedback');
        if (!errorElement) {
            errorElement = document.createElement('div');
            errorElement.className = 'invalid-feedback';
            field.parentElement.appendChild(errorElement);
        }
        errorElement.textContent = message;
    },
    
    // Limpiar error en campo
    clearFieldError: function(field) {
        field.classList.remove('is-invalid');
        const errorElement = field.parentElement.querySelector('.invalid-feedback');
        if (errorElement) {
            errorElement.remove();
        }
    },
    
    // Limpiar todos los errores
    clearAllErrors: function(form) {
        const invalidFields = form.querySelectorAll('.is-invalid');
        invalidFields.forEach(field => this.clearFieldError(field));
    }
};

// Manejo de AJAX
const AjaxHandler = {
    // Realizar petición AJAX
    request: function(url, options = {}) {
        const defaultOptions = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            timeout: CONFIG.ajaxTimeout
        };
        
        const finalOptions = { ...defaultOptions, ...options };
        
        return fetch(url, finalOptions)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .catch(error => {
                console.error('Ajax error:', error);
                throw error;
            });
    },
    
    // Eliminar elemento
    deleteItem: function(url, id, callback) {
        if (!confirm(CONFIG.confirmMessages.delete)) {
            return;
        }
        
        const body = `ajax=1&action=delete&id=${id}`;
        
        this.request(url, { body })
            .then(data => {
                if (data.success) {
                    Utils.showNotification(data.message, 'success');
                    if (callback) callback(data);
                } else {
                    Utils.showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                Utils.showNotification('Error al eliminar elemento', 'error');
            });
    },
    
    // Cambiar estado
    toggleStatus: function(url, id, action, callback) {
        const message = action === 'activate' ? CONFIG.confirmMessages.activate : CONFIG.confirmMessages.deactivate;
        
        if (!confirm(message)) {
            return;
        }
        
        const body = `ajax=1&action=${action}&id=${id}`;
        
        this.request(url, { body })
            .then(data => {
                if (data.success) {
                    Utils.showNotification(data.message, 'success');
                    if (callback) callback(data);
                } else {
                    Utils.showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                Utils.showNotification('Error al cambiar estado', 'error');
            });
    }
};

// Manejo de modales
const ModalHandler = {
    // Abrir modal
    open: function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
    },
    
    // Cerrar modal
    close: function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
    },
    
    // Cerrar modal al hacer clic fuera
    setupCloseOnOutsideClick: function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    ModalHandler.close(modalId);
                }
            });
        }
    }
};

// Manejo de tablas
const TableHandler = {
    // Ordenar tabla
    sortTable: function(table, column, direction = 'asc') {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        rows.sort((a, b) => {
            const aValue = a.cells[column].textContent.trim();
            const bValue = b.cells[column].textContent.trim();
            
            if (direction === 'asc') {
                return aValue.localeCompare(bValue, 'es', { numeric: true });
            } else {
                return bValue.localeCompare(aValue, 'es', { numeric: true });
            }
        });
        
        rows.forEach(row => tbody.appendChild(row));
    },
    
    // Filtrar tabla
    filterTable: function(table, searchTerm) {
        const tbody = table.querySelector('tbody');
        const rows = tbody.querySelectorAll('tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const matches = text.includes(searchTerm.toLowerCase());
            row.style.display = matches ? '' : 'none';
        });
    }
};

// Funciones específicas del sistema
const SystemFunctions = {
    // Generar QR
    generateQR: function(clientId) {
        const button = event.target;
        Utils.showLoading(button);
        
        AjaxHandler.request('cliente.php', {
            body: `ajax=1&action=generate_qr&id=${clientId}`
        })
        .then(data => {
            if (data.success) {
                Utils.showNotification('QR generado correctamente', 'success');
                // Actualizar la página o mostrar el QR
                location.reload();
            } else {
                Utils.showNotification(data.message, 'error');
            }
        })
        .catch(error => {
            Utils.showNotification('Error al generar QR', 'error');
        })
        .finally(() => {
            Utils.hideLoading(button);
        });
    },
    
    // Descargar PDF
    downloadPDF: function(noteId) {
        window.open(`nota.php?action=pdf&id=${noteId}`, '_blank');
    },
    
    // Cambiar estado de nota
    changeNoteStatus: function(noteId, status) {
        AjaxHandler.request('nota.php', {
            body: `ajax=1&action=change_status&id=${noteId}&status=${status}`
        })
        .then(data => {
            if (data.success) {
                Utils.showNotification(data.message, 'success');
                location.reload();
            } else {
                Utils.showNotification(data.message, 'error');
            }
        })
        .catch(error => {
            Utils.showNotification('Error al cambiar estado', 'error');
        });
    },
    
    // Calcular total automáticamente
    calculateTotal: function() {
        const cantidad = parseFloat(document.getElementById('cantidad')?.value) || 0;
        const precio = parseFloat(document.getElementById('precio_unitario')?.value) || 0;
        const total = cantidad * precio;
        
        const totalField = document.getElementById('total');
        if (totalField) {
            totalField.value = total.toFixed(2);
        }
    },
    
    // Buscar cliente por RFC
    searchClientByRFC: function(rfc) {
        if (rfc.length < 3) return;
        
        AjaxHandler.request('cliente.php', {
            body: `ajax=1&action=search_by_rfc&rfc=${encodeURIComponent(rfc)}`
        })
        .then(data => {
            if (data.success && data.client) {
                // Llenar campos del cliente
                const client = data.client;
                document.getElementById('cliente_id').value = client.id;
                document.getElementById('cliente_nombre').value = client.nombre;
                document.getElementById('cliente_email').value = client.email;
                document.getElementById('cliente_telefono').value = client.telefono;
            }
        })
        .catch(error => {
            console.error('Error al buscar cliente:', error);
        });
    }
};

// Inicialización cuando el DOM está listo
document.addEventListener('DOMContentLoaded', function() {
    // Configurar tooltips
    const tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(element => {
        element.addEventListener('mouseenter', function() {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip-text';
            tooltip.textContent = this.getAttribute('data-tooltip');
            this.appendChild(tooltip);
        });
        
        element.addEventListener('mouseleave', function() {
            const tooltip = this.querySelector('.tooltip-text');
            if (tooltip) {
                tooltip.remove();
            }
        });
    });
    
    // Configurar búsqueda en tiempo real
    const searchInputs = document.querySelectorAll('[data-search-table]');
    searchInputs.forEach(input => {
        const tableId = input.getAttribute('data-search-table');
        const table = document.getElementById(tableId);
        
        if (table) {
            input.addEventListener('input', Utils.debounce(function() {
                TableHandler.filterTable(table, this.value);
            }, 300));
        }
    });
    
    // Configurar cálculo automático de totales
    const cantidadInput = document.getElementById('cantidad');
    const precioInput = document.getElementById('precio_unitario');
    
    if (cantidadInput && precioInput) {
        cantidadInput.addEventListener('input', SystemFunctions.calculateTotal);
        precioInput.addEventListener('input', SystemFunctions.calculateTotal);
    }
    
    // Configurar búsqueda de cliente por RFC
    const rfcInput = document.getElementById('cliente_rfc');
    if (rfcInput) {
        rfcInput.addEventListener('input', Utils.debounce(function() {
            SystemFunctions.searchClientByRFC(this.value);
        }, 500));
    }
    
    // Configurar validación de formularios
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!FormHandler.validateForm(this)) {
                e.preventDefault();
                Utils.showNotification('Por favor, corrija los errores en el formulario', 'error');
            }
        });
    });
    
    // Configurar confirmación de eliminación
    const deleteButtons = document.querySelectorAll('[data-confirm-delete]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!confirm(CONFIG.confirmMessages.delete)) {
                e.preventDefault();
            }
        });
    });
    
    // Auto-hide alerts
    const alerts = document.querySelectorAll('.alert[data-auto-hide]');
    alerts.forEach(alert => {
        const delay = parseInt(alert.getAttribute('data-auto-hide')) || 5000;
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, delay);
    });
});

// Funciones globales para uso en HTML
window.deleteClient = function(id) {
    AjaxHandler.deleteItem('cliente.php', id, () => location.reload());
};

window.deleteNote = function(id) {
    AjaxHandler.deleteItem('nota.php', id, () => location.reload());
};

window.deleteUser = function(id) {
    AjaxHandler.deleteItem('usuario.php', id, () => location.reload());
};

window.deactivateUser = function(id) {
    AjaxHandler.toggleStatus('usuario.php', id, 'delete', () => location.reload());
};

window.activateUser = function(id) {
    AjaxHandler.toggleStatus('usuario.php', id, 'activate', () => location.reload());
};

window.generateQR = SystemFunctions.generateQR;
window.downloadPDF = SystemFunctions.downloadPDF;
window.changeNoteStatus = SystemFunctions.changeNoteStatus;

// Exportar para uso en módulos
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        Utils,
        FormHandler,
        AjaxHandler,
        ModalHandler,
        TableHandler,
        SystemFunctions
    };
}