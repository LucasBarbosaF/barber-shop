const phoneInput = document.querySelector('[data-mask="phone"]');

if (phoneInput) {
    const digitsOnly = (value) => value.replace(/\D/g, '');
    const maskPhone = (value) => {
        let digits = digitsOnly(value);
        if (digits.startsWith('55') && digits.length > 11) {
            digits = digits.slice(2);
        }
        digits = digits.slice(0, 11);

        if (digits.length <= 2) {
            return digits ? `(${digits}` : '';
        }

        const ddd = digits.slice(0, 2);
        const number = digits.slice(2);
        const splitAt = number.length > 8 ? 5 : 4;

        return number.length <= 4
            ? `(${ddd}) ${number}`
            : `(${ddd}) ${number.slice(0, splitAt)}-${number.slice(splitAt)}`;
    };

    phoneInput.value = maskPhone(phoneInput.value);
    phoneInput.addEventListener('input', () => {
        phoneInput.value = maskPhone(phoneInput.value);
    });
}

const bookingForm = document.querySelector('[data-booking-form]');

if (bookingForm) {
    const service = bookingForm.querySelector('[data-booking-service]');
    const barber = bookingForm.querySelector('[data-booking-barber]');
    const date = bookingForm.querySelector('[data-booking-date]');
    const slotsContainer = bookingForm.querySelector('[data-booking-slots]');
    const timeInput = bookingForm.querySelector('[data-booking-time]');
    const serviceHelp = bookingForm.querySelector('[data-booking-service-help]');
    const summaryBarber = document.querySelector('[data-summary-barber]');
    const summaryService = document.querySelector('[data-summary-service]');
    const summaryDate = document.querySelector('[data-summary-date]');
    const summaryTime = document.querySelector('[data-summary-time]');
    const summaryPrice = document.querySelector('[data-summary-price]');
    const progressSteps = document.querySelectorAll('.booking-progress li');
    let availabilityRequest;

    const showMessage = (message, isError = false) => {
        slotsContainer.replaceChildren();
        const element = document.createElement('span');
        element.className = `booking-slot-message${isError ? ' is-error' : ''}`;
        element.textContent = message;
        slotsContainer.append(element);
    };

    const updateSummary = () => {
        const selectedBarber = barber.selectedOptions[0];
        const selectedService = service.selectedOptions[0];
        const hasService = Boolean(service.value);

        summaryBarber.textContent = barber.value ? selectedBarber.textContent.trim() : 'Não selecionado';
        summaryService.textContent = hasService
            ? `${selectedService.dataset.name} · ${selectedService.dataset.duration} min`
            : 'Não selecionado';
        summaryDate.textContent = date.value
            ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long' }).format(new Date(`${date.value}T12:00:00`))
            : 'Não selecionada';
        summaryTime.textContent = timeInput.value || 'Aguardando seleção';
        summaryPrice.textContent = hasService ? `R$ ${selectedService.dataset.price}` : '—';

        const serviceReady = Boolean(barber.value && service.value);
        const timeReady = Boolean(serviceReady && date.value && timeInput.value);
        const completedSteps = [serviceReady, timeReady, false];
        const currentStep = completedSteps.findIndex((completed) => !completed);

        progressSteps.forEach((step, index) => {
            step.classList.toggle('is-complete', completedSteps[index]);
            step.classList.toggle('is-current', index === currentStep);
            if (index === currentStep) {
                step.setAttribute('aria-current', 'step');
            } else {
                step.removeAttribute('aria-current');
            }
        });
    };

    const filterServices = () => {
        const selectedBarber = barber.selectedOptions[0];
        const allowedServiceIds = new Set(
            (selectedBarber?.dataset.serviceIds ?? '')
                .split(',')
                .filter(Boolean),
        );

        [...service.options].forEach((option) => {
            if (option.value) {
                option.hidden = !allowedServiceIds.has(option.value);
            }
        });

        service.disabled = allowedServiceIds.size === 0;
        if (!allowedServiceIds.has(service.value)) {
            service.value = '';
        }

        if (!barber.value) {
            service.options[0].textContent = 'Selecione primeiro o barbeiro';
            serviceHelp.textContent = 'Os serviços disponíveis dependem do barbeiro escolhido.';
        } else if (allowedServiceIds.size === 0) {
            service.options[0].textContent = 'Nenhum serviço disponível para este barbeiro';
            serviceHelp.textContent = 'Entre em contato com a barbearia para mais informações.';
        } else {
            service.options[0].textContent = 'Selecione o serviço';
            serviceHelp.textContent = 'A lista mostra apenas os serviços realizados por este barbeiro.';
        }
        updateSummary();
    };

    const loadSlots = async () => {
        timeInput.value = '';
        updateSummary();

        if (!service.value || !barber.value || !date.value) {
            showMessage('Escolha profissional, serviço e data para ver os horários.');
            return;
        }

        availabilityRequest?.abort();
        availabilityRequest = new AbortController();
        showMessage('Buscando horários disponíveis…');

        const query = new URLSearchParams({
            service_id: service.value,
            barber_id: barber.value,
            date: date.value,
        });

        try {
            const response = await fetch(`${bookingForm.dataset.availabilityUrl}?${query}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: availabilityRequest.signal,
            });
            if (!response.ok) {
                throw new Error(`Falha ao consultar horários (${response.status}).`);
            }

            const { data } = await response.json();
            slotsContainer.replaceChildren();
            if (data.length === 0) {
                showMessage('Não há horários disponíveis nessa data. Experimente outro dia ou profissional.');
                return;
            }

            data.forEach((time) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = time;
                button.setAttribute('aria-pressed', 'false');
                button.addEventListener('click', () => {
                    slotsContainer.querySelectorAll('button').forEach((slot) => {
                        slot.setAttribute('aria-pressed', 'false');
                    });
                    button.setAttribute('aria-pressed', 'true');
                    timeInput.value = time;
                    updateSummary();
                });
                slotsContainer.append(button);
            });

            const previouslySelected = [...slotsContainer.querySelectorAll('button')]
                .find((button) => button.textContent === timeInput.defaultValue);
            if (previouslySelected) {
                previouslySelected.click();
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                showMessage('Não foi possível carregar os horários. Tente novamente em instantes.', true);
                console.error(error);
            }
        }
    };

    barber.addEventListener('change', () => {
        filterServices();
        loadSlots();
    });
    [service, date].forEach((field) => field.addEventListener('change', loadSlots));

    bookingForm.addEventListener('submit', (event) => {
        if (!timeInput.value) {
            event.preventDefault();
            showMessage('Selecione um horário disponível antes de confirmar.', true);
            slotsContainer.focus();
            return;
        }

        const phone = bookingForm.querySelector('[name="customer_phone"]');
        phone.value = phone.value.replace(/\D/g, '');
    });

    filterServices();
    loadSlots();

    document.querySelector('[data-booking-errors]')?.focus();
}
