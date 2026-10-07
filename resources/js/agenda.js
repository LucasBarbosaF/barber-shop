/*
 * Agenda — desenha o calendário (mês, semana e dia) a partir do JSON que o
 * controller embute na página e abre o popover de cada evento.
 *
 * As datas chegam como "relogio de parede" (2026-10-06T09:00:00), no horário
 * da barbearia e sem fuso: toda a grade é montada com aritmética de calendário,
 * nunca com timestamps. Assim não importa em que fuso está o navegador do
 * cliente — 09:00 continua desenhado às 09:00.
 *
 * Sem JavaScript a página não fica muda: o <noscript> da view mostra a mesma
 * tabela de agendamentos que a visão "Lista".
 */

const HOUR_HEIGHT = 48;
const MONTH_VISIBLE_MAX = 3;
const MINUTE_MS = 60_000;
const BLOCK_MIN_HEIGHT = 18;
const TINY_BLOCK_HEIGHT = 34;

const parseWall = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/.exec(value ?? '');

    if (!match) {
        return null;
    }

    return new Date(+match[1], +match[2] - 1, +match[3], +(match[4] ?? 0), +(match[5] ?? 0), +(match[6] ?? 0));
};

const parseDay = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');

    return match ? new Date(+match[1], +match[2] - 1, +match[3]) : null;
};

const addDays = (date, amount) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + amount);

const dayKey = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
].join('-');

const formatMinutes = (minutes) => {
    const clamped = Math.max(0, Math.min(24 * 60 - 1, Math.round(minutes)));

    return `${String(Math.floor(clamped / 60)).padStart(2, '0')}:${String(clamped % 60).padStart(2, '0')}`;
};

const element = (tag, className, text) => {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
};

/*
 * Eventos recortados para um dia: o mesmo evento vira um segmento por dia que
 * atravessa, já com os minutos dentro desse dia. É o que permite desenhar um
 * bloqueio de segunda a quarta sem inventar regra de evento multidiário.
 */
const segmentsForDay = (events, day) => {
    const dayStart = day.getTime();
    const dayEnd = addDays(day, 1).getTime();
    const segments = [];

    for (const event of events) {
        const start = parseWall(event.start);
        const end = parseWall(event.end);

        if (!start || !end || end.getTime() <= dayStart || start.getTime() >= dayEnd) {
            continue;
        }

        segments.push({
            event,
            startsBefore: start.getTime() < dayStart,
            endsAfter: end.getTime() > dayEnd,
            startMin: (Math.max(start.getTime(), dayStart) - dayStart) / MINUTE_MS,
            endMin: (Math.min(end.getTime(), dayEnd) - dayStart) / MINUTE_MS,
        });
    }

    return segments.sort((a, b) => a.startMin - b.startMin || b.endMin - a.endMin);
};

const buildChip = (segment) => {
    const { event, startsBefore } = segment;
    const chip = element('button', `agenda-chip${event.kind === 'blocked' ? ' is-blocked' : ''}`);
    chip.type = 'button';
    chip.dataset.status = event.status;
    chip.dataset.eventId = event.id;
    chip.setAttribute(
        'aria-label',
        `${startsBefore ? 'antes das ' + formatMinutes(segment.startMin) : event.time} — ${event.title} — ${event.statusLabel}`,
    );
    chip.append(
        element('span', 'agenda-chip-time', startsBefore ? '…' : formatMinutes(segment.startMin)),
        element('span', 'agenda-chip-title', event.title),
    );

    return chip;
};

const buildBlock = (segment) => {
    const { event, startsBefore, endsAfter, startMin, endMin } = segment;
    const height = Math.max(BLOCK_MIN_HEIGHT, ((endMin - startMin) / 60) * HOUR_HEIGHT);
    const block = element('button', `agenda-block${event.kind === 'blocked' ? ' is-blocked' : ''}`);
    block.type = 'button';

    if (height < TINY_BLOCK_HEIGHT) {
        block.classList.add('is-tiny');
    }

    block.dataset.status = event.status;
    block.dataset.eventId = event.id;
    block.style.top = `${(startMin / 60) * HOUR_HEIGHT}px`;
    block.style.height = `${height}px`;
    block.setAttribute(
        'aria-label',
        `${startsBefore ? '…' : formatMinutes(startMin)} às ${endsAfter ? '…' : formatMinutes(endMin)} — ${event.title} — ${event.statusLabel}`,
    );
    block.append(
        element('span', 'agenda-block-time', `${startsBefore ? '…' : formatMinutes(startMin)}–${endsAfter ? '…' : formatMinutes(endMin)}`),
        ' ',
        element('span', 'agenda-block-title', event.title),
        element('span', 'agenda-block-meta', [event.service, event.barber].filter(Boolean).join(' · ')),
    );

    return block;
};

/*
 * Colunas da grade de horários: eventos sobrepostos dividem a largura, e um
 * cluster (grupo encadeado por sobreposições) só se resolve quando todos os
 * seus eventos têm coluna — é o que impede um evento longo de empurrar os
 * vizinhos para fora do dia.
 */
const layoutSegments = (segments) => {
    const clusters = [];
    let cluster = [];
    let clusterEnd = -1;

    for (const segment of segments) {
        if (cluster.length > 0 && segment.startMin >= clusterEnd) {
            clusters.push(cluster);
            cluster = [];
            clusterEnd = -1;
        }

        cluster.push(segment);
        clusterEnd = Math.max(clusterEnd, segment.endMin);
    }

    if (cluster.length > 0) {
        clusters.push(cluster);
    }

    for (const current of clusters) {
        const laneEnds = [];

        for (const segment of current) {
            const lane = laneEnds.findIndex((endMin) => endMin <= segment.startMin);

            if (lane === -1) {
                laneEnds.push(segment.endMin);
                segment.lane = laneEnds.length - 1;
            } else {
                laneEnds[lane] = segment.endMin;
                segment.lane = lane;
            }
        }

        for (const segment of current) {
            segment.lanes = laneEnds.length;
        }
    }

    return segments;
};

const renderMonth = (root, events, config) => {
    const body = root.querySelector('[data-agenda-month-body]');

    if (!body) {
        return 0;
    }

    const firstDay = parseDay(config.start);
    const lastDay = parseDay(config.end);
    const anchor = parseDay(config.date);

    if (!firstDay || !lastDay || !anchor) {
        return 0;
    }

    let rendered = 0;

    for (let day = firstDay; day <= lastDay; day = addDays(day, 1)) {
        const key = dayKey(day);
        const cell = element('div', 'agenda-mcell');
        cell.dataset.day = key;

        if (day.getDay() === 0 || day.getDay() === 6) {
            cell.classList.add('is-weekend');
        }

        if (key === config.today) {
            cell.classList.add('is-today');
        }

        if (day.getMonth() !== anchor.getMonth() || day.getFullYear() !== anchor.getFullYear()) {
            cell.classList.add('is-out');
        }

        const top = element('div', 'agenda-mcell-top');
        const number = element('a', 'agenda-mcell-num', String(day.getDate()));
        number.href = config.dayUrl.replace('__DATE__', key);
        number.setAttribute('aria-label', `Ver ${key}`);
        top.append(number);

        const list = element('div', 'agenda-mcell-events');
        const segments = segmentsForDay(events, day);
        rendered += segments.length;

        segments.forEach((segment, index) => {
            const chip = buildChip(segment);
            chip.hidden = index >= MONTH_VISIBLE_MAX;
            list.append(chip);
        });

        if (segments.length > MONTH_VISIBLE_MAX) {
            const hiddenCount = segments.length - MONTH_VISIBLE_MAX;
            const more = element('button', 'agenda-more', `+${hiddenCount} mais`);
            more.type = 'button';
            more.setAttribute('aria-label', `Mostrar mais ${hiddenCount} agendamentos deste dia`);
            more.addEventListener('click', () => {
                const expanded = more.dataset.expanded === 'true';
                more.dataset.expanded = String(!expanded);

                [...list.querySelectorAll('.agenda-chip')].forEach((chip, index) => {
                    chip.hidden = expanded ? index >= MONTH_VISIBLE_MAX : false;
                });

                more.textContent = expanded ? `+${hiddenCount} mais` : 'Mostrar menos';
            });
            list.append(more);
        }

        cell.append(top, list);
        body.append(cell);
    }

    return rendered;
};

const renderTimeGrid = (root, events, config) => {
    let rendered = 0;

    root.querySelectorAll('.agenda-daycol[data-day]').forEach((column) => {
        const day = parseDay(column.dataset.day);

        if (!day) {
            return;
        }

        const segments = layoutSegments(segmentsForDay(events, day));

        for (const segment of segments) {
            const block = buildBlock(segment);
            block.style.left = `calc(${(segment.lane / segment.lanes) * 100}% + 2px)`;
            block.style.width = `calc(${(1 / segment.lanes) * 100}% - 4px)`;
            column.append(block);
            rendered += 1;
        }

        if (column.dataset.day !== config.today || !config.now) {
            return;
        }

        const now = parseWall(config.now);

        if (!now) {
            return;
        }

        const minutes = now.getHours() * 60 + now.getMinutes();
        const line = element('div', 'agenda-nowline');
        line.style.top = `${(minutes / 60) * HOUR_HEIGHT}px`;
        line.setAttribute('aria-hidden', 'true');
        column.append(line);
    });

    return rendered;
};

const scrollToRelevantHour = (root, config) => {
    const scroller = root.querySelector('.agenda-timegrid');

    if (!scroller) {
        return;
    }

    const now = parseWall(config.now);
    const todayVisible = now && dayKey(now) >= config.start && dayKey(now) <= config.end;
    const targetMinutes = todayVisible ? now.getHours() * 60 + now.getMinutes() : 7 * 60;
    const top = (targetMinutes / 60) * HOUR_HEIGHT;

    scroller.scrollTop = Math.max(0, top - scroller.clientHeight / 3);
};

const row = (icon, content) => {
    const item = element('li', 'agenda-popover-row');
    item.append(element('i', `fas ${icon}`), content);

    return item;
};

const buildAction = (action, csrfToken) => {
    if (action.method === 'GET') {
        const link = element('a', 'btn btn-sm btn-outline-primary', action.label);
        link.href = action.url;

        return link;
    }

    const form = element('form');
    form.method = 'POST';
    form.action = action.url;

    const token = element('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = csrfToken;
    form.append(token);

    if (action.method === 'DELETE') {
        const method = element('input');
        method.type = 'hidden';
        method.name = '_method';
        method.value = 'DELETE';
        form.append(method);
    }

    const submit = element('button', action.method === 'DELETE' ? 'btn btn-sm btn-outline-danger' : 'btn btn-sm btn-primary', action.label);
    submit.type = 'submit';
    form.append(submit);

    return form;
};

/*
 * Formulário de troca de status: só os alvos que a matriz do enum permite
 * (o controller monta `statusTargets`; em atendimento/concluído não vêm aqui).
 * O PATCH vai por `_method` escondido, como nos demais forms da aplicação.
 */
const buildStatusForm = (event, csrfToken) => {
    const form = element('form', 'agenda-popover-status-form');
    form.method = 'POST';
    form.action = event.statusUrl;

    const token = element('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = csrfToken;

    const method = element('input');
    method.type = 'hidden';
    method.name = '_method';
    method.value = 'PATCH';

    const selectId = `status-${event.id}`;
    const label = element('label', 'form-label mb-0', 'Mudar status');
    label.setAttribute('for', selectId);

    const select = element('select', 'form-select form-select-sm');
    select.id = selectId;
    select.name = 'status';

    for (const target of event.statusTargets) {
        const option = element('option', '', target.label);
        option.value = target.value;
        select.append(option);
    }

    const submit = element('button', 'btn btn-sm btn-primary', 'Salvar');
    submit.type = 'submit';

    form.append(token, method, label, select, submit);

    return form;
};

const openPopover = (popover, event, anchor) => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const start = parseWall(event.start);
    popover.replaceChildren();

    const head = element('div', 'agenda-popover-head');
    head.append(element('h3', 'agenda-popover-title', event.title));

    const close = element('button', 'agenda-popover-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Fechar');
    close.addEventListener('click', () => {
        popover.hidden = true;
    });
    head.append(close);
    popover.append(head);

    const status = element('span', 'agenda-popover-status', event.statusLabel);
    status.dataset.status = event.status;
    popover.append(status);

    const rows = element('ul', 'agenda-popover-rows');

    if (start) {
        const dateLabel = new Intl.DateTimeFormat('pt-BR', {
            weekday: 'long',
            day: '2-digit',
            month: 'long',
            year: 'numeric',
        }).format(start);
        rows.append(row('fa-calendar-days', dateLabel));
    }

    rows.append(row('fa-clock', event.time));

    if (event.service) {
        rows.append(row('fa-scissors', event.service));
    }

    if (event.barber) {
        rows.append(row('fa-user-tie', event.barber));
    }

    if (event.phone) {
        const phone = element('a', '', event.phone);
        phone.href = `tel:${event.phone.replace(/\D/g, '')}`;
        rows.append(row('fa-phone', phone));
    }

    popover.append(rows);

    if (event.statusTargets?.length && event.statusUrl) {
        popover.append(buildStatusForm(event, csrfToken));
    }

    if (event.actions?.length) {
        const actions = element('div', 'agenda-popover-actions');

        for (const action of event.actions) {
            actions.append(buildAction(action, csrfToken));
        }

        popover.append(actions);
    }

    popover.hidden = false;

    const rect = anchor.getBoundingClientRect();
    const box = popover.getBoundingClientRect();
    const gap = 8;
    let left = rect.right + gap;

    if (left + box.width > window.innerWidth - gap) {
        left = rect.left - box.width - gap;
    }

    left = Math.max(gap, Math.min(left, window.innerWidth - box.width - gap));

    let top = rect.top;

    if (top + box.height > window.innerHeight - gap) {
        top = window.innerHeight - box.height - gap;
    }

    popover.style.left = `${left}px`;
    popover.style.top = `${Math.max(gap, top)}px`;
};

const initAgenda = (root) => {
    const payload = root.querySelector('script[data-agenda-events]');
    let events = [];

    try {
        events = JSON.parse(payload?.textContent ?? '[]');
    } catch (error) {
        console.error('Não foi possível ler os eventos da agenda.', error);
    }

    const config = {
        view: root.dataset.view,
        date: root.dataset.date,
        start: root.dataset.start,
        end: root.dataset.end,
        today: root.dataset.today,
        now: root.dataset.now,
        dayUrl: root.dataset.dayUrl,
    };
    const byId = new Map(events.map((event) => [event.id, event]));
    const rendered = config.view === 'month'
        ? renderMonth(root, events, config)
        : renderTimeGrid(root, events, config);

    const empty = root.querySelector('[data-agenda-empty]');

    if (empty) {
        empty.hidden = rendered > 0;
    }

    if (config.view !== 'month') {
        scrollToRelevantHour(root, config);
    }

    const popover = element('div', 'agenda-popover');
    popover.hidden = true;
    popover.setAttribute('role', 'dialog');
    popover.setAttribute('aria-label', 'Detalhes do agendamento');
    document.body.append(popover);

    root.addEventListener('click', (click) => {
        const trigger = click.target.closest('[data-event-id]');

        if (!trigger) {
            return;
        }

        const event = byId.get(trigger.dataset.eventId);

        if (event) {
            openPopover(popover, event, trigger);
        }
    });

    document.addEventListener('click', (click) => {
        if (popover.hidden || popover.contains(click.target)) {
            return;
        }

        if (click.target.closest('[data-event-id]')) {
            return;
        }

        popover.hidden = true;
    });

    document.addEventListener('keydown', (key) => {
        if (key.key === 'Escape') {
            popover.hidden = true;
        }
    });
};

const agendaRoot = document.querySelector('[data-agenda-calendar]');

if (agendaRoot) {
    initAgenda(agendaRoot);
}
