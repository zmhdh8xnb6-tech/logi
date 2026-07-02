document.querySelectorAll('.campo-moeda').forEach(function (campo) {
    campo.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9.,]/g, '');
    });

    campo.addEventListener('blur', function () {
        let valor = this.value.trim();

        if (valor === '') {
            return;
        }

        if (valor.includes(',')) {
            valor = valor.replace(/\./g, '').replace(',', '.');
        } else {
            const partes = valor.split('.');

            if (partes.length > 2) {
                valor = partes.join('');
            } else if (partes.length === 2 && partes[1].length === 3) {
                valor = partes.join('');
            }
        }

        const numero = Number(valor);

        if (!Number.isFinite(numero)) {
            return;
        }

        this.value = numero.toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    });
});