/*
 * JS do painel: Bootstrap (dropdown do usuário, alerts, etc.) + AdminLTE
 * (pushmenu da sidebar, treeview, tema). Ambos são ESM e se auto-inicializam
 * no DOMContentLoaded.
 */
import { Modal } from 'bootstrap';
import 'admin-lte';

const digitsOnly = (value) => value.replace(/\D/g, '');

const formatPhone = (value) => {
    let digits = digitsOnly(value);

    if (digits.startsWith('55') && digits.length > 11) {
        digits = digits.slice(2);
    }

    digits = digits.slice(0, 11);

    if (digits.length <= 2) {
        return digits ? `(${digits}` : '';
    }

    const areaCode = digits.slice(0, 2);
    const number = digits.slice(2);

    if (number.length <= 4) {
        return `(${areaCode}) ${number}`;
    }

    const splitAt = number.length > 8 ? 5 : 4;

    return `(${areaCode}) ${number.slice(0, splitAt)}-${number.slice(splitAt)}`;
};

const formatCurrency = (value) => {
    const digits = digitsOnly(value).slice(0, 12);

    if (!digits) {
        return '';
    }

    const cents = digits.slice(-2).padStart(2, '0');
    const whole = (digits.slice(0, -2) || '0').replace(/^0+(?=\d)/, '');
    const formattedWhole = Number(whole).toLocaleString('pt-BR');

    return `${formattedWhole},${cents}`;
};

const applyMask = (input) => {
    input.value = input.dataset.mask === 'phone'
        ? formatPhone(input.value)
        : formatCurrency(input.value);
};

document.querySelectorAll('[data-mask="phone"], [data-mask="currency"]').forEach((input) => {
    applyMask(input);
    input.addEventListener('input', () => applyMask(input));
});

const agendaStartDate = document.querySelector('[data-agenda-start-date]');
const agendaEndDate = document.querySelector('[data-agenda-end-date]');

if (agendaStartDate && agendaEndDate) {
    const syncAgendaDateRange = () => {
        agendaEndDate.min = agendaStartDate.value;
        if (agendaEndDate.value < agendaStartDate.value) {
            agendaEndDate.value = agendaStartDate.value;
        }
    };

    agendaStartDate.addEventListener('change', syncAgendaDateRange);
    syncAgendaDateRange();
}

document.querySelectorAll('[data-toggle-password]').forEach((toggle) => {
    const password = document.getElementById('password');

    if (!password) {
        return;
    }

    toggle.addEventListener('click', () => {
        const isVisible = password.type === 'text';
        password.type = isVisible ? 'password' : 'text';
        toggle.setAttribute('aria-pressed', String(!isVisible));
        toggle.setAttribute('aria-label', isVisible ? 'Mostrar senha' : 'Ocultar senha');
        toggle.querySelector('i').classList.toggle('fa-eye', isVisible);
        toggle.querySelector('i').classList.toggle('fa-eye-slash', !isVisible);
    });
});

document.querySelector('[data-login-errors]')?.focus();

/*
 * Tabelas com a classe `table-stack` viram uma pilha de cartões abaixo de
 * 768px (CSS em admin.css). Aqui os `data-label` de cada célula são copiados
 * do cabeçalho — assim colunas condicionais (agenda com ou sem a coluna
 * Barbeiro) continuam pareadas com o título certo — e a semântica de tabela
 * que o `display: block` remove é devolvida via papéis ARIA.
 */
document.querySelectorAll('table.table-stack').forEach((table) => {
    const headerRow = table.tHead?.rows[0];
    const labels = headerRow ? [...headerRow.cells].map((cell) => cell.textContent.trim()) : [];

    table.setAttribute('role', 'table');
    table.tHead?.setAttribute('role', 'rowgroup');
    table.tFoot?.setAttribute('role', 'rowgroup');
    [...table.tBodies].forEach((body) => body.setAttribute('role', 'rowgroup'));

    if (headerRow) {
        headerRow.setAttribute('role', 'row');
        [...headerRow.cells].forEach((cell) => cell.setAttribute('role', 'columnheader'));
    }

    [...table.tBodies].forEach((body) => {
        [...body.rows].forEach((row) => {
            row.setAttribute('role', 'row');
            const cells = [...row.cells];

            // Cabeçalho de grupo (ex.: "segunda, 06/10") vira faixa do cartão.
            if (cells.length === 1 && cells[0].tagName === 'TH') {
                cells[0].setAttribute('role', 'rowheader');
                row.classList.add('is-stack-group');
                return;
            }

            cells.forEach((cell, index) => {
                cell.setAttribute('role', cell.tagName === 'TH' ? 'rowheader' : 'cell');

                if (cell.tagName === 'TD' && labels.length === cells.length) {
                    cell.dataset.label = labels[index];
                }
            });
        });
    });

    [...(table.tFoot?.rows ?? [])].forEach((row) => {
        row.setAttribute('role', 'row');
        [...row.cells].forEach((cell) => cell.setAttribute('role', cell.tagName === 'TH' ? 'rowheader' : 'cell'));
    });
});

document.querySelectorAll('[data-auto-show-modal]').forEach((modal) => {
    Modal.getOrCreateInstance(modal).show();
});

document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', () => {
        form.querySelectorAll('[data-mask="phone"]').forEach((input) => {
            input.value = digitsOnly(input.value);
        });
        form.querySelectorAll('[data-mask="currency"]').forEach((input) => {
            input.value = input.value.replace(/\./g, '').replace(',', '.');
        });
    });

    const notificationBell = document.querySelector('[data-appointment-notifications]');

    if (notificationBell) {
        const notificationList = notificationBell.querySelector('[data-notification-list]');
        const notificationCount = notificationBell.querySelector('[data-notification-count]');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

        const refreshNotifications = async () => {
            const response = await fetch(notificationBell.dataset.url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Falha ao consultar notificações (${response.status}).`);
            }

            const { data } = await response.json();
            notificationCount.textContent = String(data.length);
            notificationCount.hidden = data.length === 0;
            notificationList.replaceChildren();

            if (data.length === 0) {
                const empty = document.createElement('span');
                empty.className = 'dropdown-item-text text-secondary';
                empty.textContent = 'Nenhum novo agendamento.';
                notificationList.append(empty);
                return;
            }

            data.forEach((notification) => {
                const item = document.createElement('div');
                item.className = 'dropdown-item d-flex align-items-start gap-2 text-wrap';
                const link = document.createElement('a');
                link.className = 'text-reset text-decoration-none flex-grow-1';
                link.href = notification.url;
                link.textContent = notification.message;
                const read = document.createElement('button');
                read.className = 'btn btn-sm btn-outline-secondary';
                read.type = 'button';
                read.setAttribute('aria-label', 'Marcar notificação como lida');
                read.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i>';
                read.addEventListener('click', async () => {
                    const readUrl = notificationBell.dataset.readUrl.replace('__ID__', notification.id);
                    const result = await fetch(readUrl, {
                        method: 'PATCH',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        credentials: 'same-origin',
                    });

                    if (!result.ok) {
                        throw new Error(`Falha ao atualizar notificação (${result.status}).`);
                    }

                    await refreshNotifications();
                });
                item.append(link, read);
                notificationList.append(item);
            });
        };

        refreshNotifications().catch((error) => console.error(error));
        window.setInterval(() => {
            refreshNotifications().catch((error) => console.error(error));
        }, 60_000);
    }
});
