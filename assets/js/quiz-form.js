(() => {
    document.querySelectorAll('form[data-quiz-form]').forEach((form) => {
        const notice = form.querySelector('[data-quiz-notice]');
        const list = form.querySelector('[data-quiz-missing]');
        const validate = () => {
            const missing = [];
            list.replaceChildren();
            form.querySelectorAll('[data-quiz-question]').forEach((question) => {
                const inputs = [...question.querySelectorAll('input')];
                const answered = inputs.some((input) =>
                    ['radio', 'checkbox'].includes(input.type) ? input.checked : input.value.trim() !== ''
                );
                let error = question.querySelector('[data-quiz-error]');
                if (!error) {
                    error = document.createElement('p');
                    error.dataset.quizError = '';
                    error.id = `${question.id}-error`;
                    error.className = 'mt-3 font-semibold text-red-700';
                    error.textContent = 'กรุณาตอบข้อนี้';
                    question.append(error);
                }
                error.hidden = answered;
                inputs.forEach((input) => {
                    if (answered) {
                        input.removeAttribute('aria-invalid');
                        input.removeAttribute('aria-describedby');
                    } else {
                        input.setAttribute('aria-invalid', 'true');
                        input.setAttribute('aria-describedby', error.id);
                    }
                });
                if (!answered) {
                    missing.push(question);
                    const link = document.createElement('a');
                    link.href = `#${question.id}`;
                    link.className = 'mr-3 underline';
                    link.textContent = `ข้อที่ ${question.dataset.questionNumber}`;
                    list.append(link);
                }
            });
            notice.hidden = missing.length === 0;
            return missing;
        };
        list.addEventListener('click', (event) => {
            const link = event.target.closest('a');
            if (!link) return;
            const question = document.getElementById(link.hash.slice(1));
            if (question) {
                event.preventDefault();
                question.scrollIntoView({ block: 'center' });
                question.querySelector('input')?.focus({ preventScroll: true });
            }
        });
        form.addEventListener('submit', (event) => {
            if (validate().length) {
                event.preventDefault();
                notice.focus();
            }
        });
        form.addEventListener('input', () => {
            if (form.querySelector('[aria-invalid]')) validate();
        });
        if (!notice.hidden) validate();
    });
})();
