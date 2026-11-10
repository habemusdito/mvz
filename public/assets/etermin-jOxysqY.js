/*
 * Kalender für die Blutentnahme-Terminbuchung (/blutentnahme).
 * Lädt die freien Tage und Uhrzeiten über den Etermin-Controller.
 */
import { Datepicker } from 'flowbite-datepicker';

Datepicker.locales.de = {
    days: ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'],
    daysShort: ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'],
    daysMin: ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'],
    months: ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
    monthsShort: ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'],
    today: 'Heute',
    clear: 'Löschen',
    weekStart: 1,
    format: 'dd.mm.yyyy',
};

const dateFormatter = new Intl.DateTimeFormat('de-DE', { weekday: 'long', day: '2-digit', month: 'long' });

// Datum als Y-m-d in lokaler Zeit (toISOString würde nach UTC verschieben)
function toIsoDate(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function parseIsoDate(value) {
    const [year, month, day] = value.split('-').map(Number);
    return new Date(year, month - 1, day);
}

async function fetchJson(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.error || 'Die freien Termine konnten nicht geladen werden.');
    }
    return data;
}

function renderSlots(container, date, slots) {
    container.replaceChildren();

    const heading = document.createElement('h2');
    heading.className = 'font-heading text-2xl text-gray-900 mb-4';
    heading.textContent = `Freie Termine für ${dateFormatter.format(date)}`;
    container.append(heading);

    if (slots.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'text-gray-600';
        empty.textContent = 'An diesem Tag sind leider keine Termine mehr frei.';
        container.append(empty);
        return;
    }

    const list = document.createElement('div');
    list.className = 'grid grid-cols-3 sm:grid-cols-4 md:grid-cols-3 lg:grid-cols-4 gap-3';
    for (const slot of slots) {
        const link = document.createElement('a');
        link.className = 'btn-white justify-center';
        link.href = slot.url;
        link.textContent = slot.time;
        list.append(link);
    }
    container.append(list);
}

function renderMessage(container, text) {
    container.replaceChildren();
    const message = document.createElement('p');
    message.className = 'text-rose-800';
    message.textContent = text;
    container.append(message);
}

async function init(root) {
    const calendar = root.querySelector('[data-etermin-calendar]');
    const status = root.querySelector('[data-etermin-status]');
    const slotsContainer = root.querySelector('[data-etermin-slots]');

    let availableDays;
    try {
        availableDays = new Set(await fetchJson(root.dataset.daysUrl));
    } catch (error) {
        status.textContent = error.message;
        status.classList.add('text-rose-800');
        return;
    }

    status.textContent = availableDays.size > 0
        ? 'Tage mit freien Terminen sind auswählbar.'
        : 'In den nächsten Wochen sind leider keine Termine frei.';

    new Datepicker(calendar, {
        language: 'de',
        minDate: parseIsoDate(root.dataset.minDate),
        maxDate: parseIsoDate(root.dataset.maxDate),
        todayHighlight: true,
        beforeShowDay: (date) => availableDays.has(toIsoDate(date)),
    });

    calendar.addEventListener('changeDate', async (event) => {
        const date = event.detail.date;
        if (!date) {
            return;
        }

        slotsContainer.setAttribute('aria-busy', 'true');
        try {
            const url = root.dataset.slotsUrl.replace('0000-00-00', toIsoDate(date));
            renderSlots(slotsContainer, date, await fetchJson(url));
        } catch (error) {
            renderMessage(slotsContainer, error.message);
        } finally {
            slotsContainer.removeAttribute('aria-busy');
        }
    });
}

document.querySelectorAll('[data-etermin]').forEach(init);
