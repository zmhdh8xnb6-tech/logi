(function () {
    'use strict';

    const horariosPadrao = {
        1: { trabalha: true, entrada_1: '08:00', saida_1: '12:00', entrada_2: '13:00', saida_2: '18:00' },
        2: { trabalha: true, entrada_1: '08:00', saida_1: '12:00', entrada_2: '13:00', saida_2: '18:00' },
        3: { trabalha: true, entrada_1: '08:00', saida_1: '12:00', entrada_2: '13:00', saida_2: '18:00' },
        4: { trabalha: true, entrada_1: '08:00', saida_1: '12:00', entrada_2: '13:00', saida_2: '18:00' },
        5: { trabalha: true, entrada_1: '08:00', saida_1: '12:00', entrada_2: '13:00', saida_2: '17:00' },
        6: { trabalha: false, entrada_1: '', saida_1: '', entrada_2: '', saida_2: '' },
        7: { trabalha: false, entrada_1: '', saida_1: '', entrada_2: '', saida_2: '' }
    };

    function minutosHora(valor) {
        if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(valor || '')) {
            return null;
        }

        const partes = valor.split(':').map(Number);
        return (partes[0] * 60) + partes[1];
    }

    function minutosIntervalo(inicio, fim) {
        const primeiro = minutosHora(inicio);
        const ultimo = minutosHora(fim);
        return primeiro !== null && ultimo !== null && ultimo > primeiro ? ultimo - primeiro : 0;
    }

    function minutosMarcacoes(dados) {
        return minutosIntervalo(dados.entrada_1, dados.saida_1)
            + minutosIntervalo(dados.entrada_2, dados.saida_2);
    }

    function formatarMinutos(minutos, comSinal) {
        let sinal = '';

        if (minutos < 0) {
            sinal = '-';
        } else if (comSinal && minutos > 0) {
            sinal = '+';
        }

        const absoluto = Math.abs(minutos);
        return sinal + Math.floor(absoluto / 60) + 'h' + String(absoluto % 60).padStart(2, '0');
    }

    function dadosLinha(linha) {
        const dados = {};
        linha.querySelectorAll('.jornada-hora').forEach(function (campo) {
            dados[campo.dataset.campo] = campo.value;
        });
        return dados;
    }

    function atualizarLinhaJornada(linha) {
        const trabalha = linha.querySelector('.jornada-trabalha');
        const campos = linha.querySelectorAll('.jornada-hora');
        const ativa = Boolean(trabalha && trabalha.checked);

        linha.classList.toggle('jornada-ativa', ativa);
        campos.forEach(function (campo) {
            campo.disabled = !ativa;
            campo.classList.remove('is-invalid');
        });

        const total = ativa ? minutosMarcacoes(dadosLinha(linha)) : 0;
        const totalElemento = linha.querySelector('.jornada-total');

        if (totalElemento) {
            totalElemento.textContent = formatarMinutos(total, false);
        }

        return total;
    }

    function atualizarCargaSemanal() {
        let total = 0;
        document.querySelectorAll('.jornada-linha').forEach(function (linha) {
            total += atualizarLinhaJornada(linha);
        });

        const resumo = document.getElementById('cargaSemanalModal');
        const aviso = document.getElementById('avisoCargaSemanal');

        if (resumo) {
            resumo.textContent = formatarMinutos(total, false) + ' por semana';
        }

        if (aviso) {
            aviso.classList.toggle('d-none', total <= 44 * 60);
        }
    }

    function preencherModalFuncionario(configuracao) {
        const dados = configuracao || {};
        const editando = Boolean(dados.id);
        const id = document.getElementById('funcionarioIdModal');
        const nome = document.getElementById('funcionarioNome');
        const ativo = document.getElementById('funcionarioAtivo');
        const grupoAtivo = document.getElementById('grupoFuncionarioAtivo');
        const excluir = document.getElementById('btnExcluirFuncionario');
        const titulo = document.getElementById('tituloModalFuncionario');
        const horarios = dados.horarios || Object.values(horariosPadrao);
        const porDia = {};

        horarios.forEach(function (horario) {
            porDia[Number(horario.dia_semana)] = horario;
        });

        if (id) id.value = dados.id || '';
        if (nome) {
            nome.value = dados.nome || '';
            nome.classList.remove('is-invalid');
        }
        if (ativo) ativo.checked = dados.ativo !== 0;
        if (grupoAtivo) grupoAtivo.classList.toggle('d-none', !editando);
        if (excluir) excluir.classList.toggle('d-none', !editando);
        if (titulo) titulo.textContent = editando ? 'Editar funcionário e jornada' : 'Novo funcionário';

        document.querySelectorAll('.jornada-linha').forEach(function (linha) {
            const dia = Number(linha.dataset.dia);
            const horario = porDia[dia] || horariosPadrao[dia];
            const trabalha = linha.querySelector('.jornada-trabalha');

            if (trabalha) {
                trabalha.checked = Boolean(Number(horario.trabalha ?? (horario.trabalha ? 1 : 0)));
            }

            linha.querySelectorAll('.jornada-hora').forEach(function (campo) {
                campo.value = horario[campo.dataset.campo] || '';
                campo.classList.remove('is-invalid');
            });
        });

        atualizarCargaSemanal();
    }

    function validarFuncionario(evento) {
        const formulario = evento.currentTarget;
        const nome = document.getElementById('funcionarioNome');
        let valido = true;

        if (!nome || nome.value.trim() === '') {
            nome?.classList.add('is-invalid');
            valido = false;
        } else {
            nome.classList.remove('is-invalid');
        }

        document.querySelectorAll('.jornada-linha').forEach(function (linha) {
            const trabalha = linha.querySelector('.jornada-trabalha');

            if (!trabalha?.checked) {
                return;
            }

            const campos = {};
            linha.querySelectorAll('.jornada-hora').forEach(function (campo) {
                campos[campo.dataset.campo] = campo;
                campo.classList.remove('is-invalid');
            });

            const primeiraCompleta = campos.entrada_1.value && campos.saida_1.value
                && minutosIntervalo(campos.entrada_1.value, campos.saida_1.value) > 0;
            const segundoVazio = !campos.entrada_2.value && !campos.saida_2.value;
            const segundoCompleto = campos.entrada_2.value && campos.saida_2.value
                && minutosIntervalo(campos.entrada_2.value, campos.saida_2.value) > 0;

            if (!primeiraCompleta) {
                campos.entrada_1.classList.add('is-invalid');
                campos.saida_1.classList.add('is-invalid');
                valido = false;
            }

            if (!segundoVazio && !segundoCompleto) {
                campos.entrada_2.classList.add('is-invalid');
                campos.saida_2.classList.add('is-invalid');
                valido = false;
            }
        });

        if (!valido) {
            evento.preventDefault();
            formulario.querySelector('.is-invalid')?.focus();
        }
    }

    function atualizarLinhaRegistro(campo) {
        const linha = campo.closest('tr');

        if (!linha) {
            return;
        }

        const horarios = Array.from(linha.querySelectorAll('.ponto-hora-registro')).map(function (item) {
            return item.value;
        });
        const trabalhado = minutosIntervalo(horarios[0], horarios[1])
            + minutosIntervalo(horarios[2], horarios[3]);
        const total = linha.querySelector('.ponto-total-dia');
        const saldo = linha.querySelector('.ponto-saldo-dia');
        const previsto = Number(total?.dataset.previsto || 0);

        if (total) total.textContent = formatarMinutos(trabalhado, false);
        if (saldo) {
            const diferenca = trabalhado - previsto;
            saldo.textContent = formatarMinutos(diferenca, true);
            saldo.classList.toggle('text-danger', diferenca < 0);
            saldo.classList.toggle('text-success', diferenca >= 0);
        }
    }

    function dataIsoValida(ano, mes, dia) {
        const data = new Date(Date.UTC(ano, mes - 1, dia));
        return data.getUTCFullYear() === ano
            && data.getUTCMonth() === mes - 1
            && data.getUTCDate() === dia;
    }

    function dataDaLinha(texto, mesSelecionado) {
        const partesMes = mesSelecionado.split('-').map(Number);
        let ano = partesMes[0];
        let mes = partesMes[1];
        let dia = null;
        const dataCompleta = texto.match(/(?:^|\s)([0-3]?\d)[\/.\-]([01]?\d)[\/.\-](\d{2}|\d{4})(?=\s|$)/);

        if (dataCompleta) {
            dia = Number(dataCompleta[1]);
            mes = Number(dataCompleta[2]);
            ano = Number(dataCompleta[3]);
            if (ano < 100) ano += 2000;
        } else {
            const somenteDia = texto.match(/^\s*([0-3]?\d)(?:\s|$)/);
            dia = somenteDia ? Number(somenteDia[1]) : null;
        }

        if (!dia || !dataIsoValida(ano, mes, dia)) {
            return null;
        }

        const iso = String(ano).padStart(4, '0') + '-'
            + String(mes).padStart(2, '0') + '-'
            + String(dia).padStart(2, '0');
        return iso.startsWith(mesSelecionado + '-') ? iso : null;
    }

    function linhasDoPdf(itens) {
        const linhas = [];

        itens
            .filter(function (item) { return String(item.str || '').trim() !== ''; })
            .sort(function (a, b) {
                const diferencaY = b.transform[5] - a.transform[5];
                return Math.abs(diferencaY) > 2.5 ? diferencaY : a.transform[4] - b.transform[4];
            })
            .forEach(function (item) {
                const y = item.transform[5];
                let linha = linhas.find(function (existente) {
                    return Math.abs(existente.y - y) <= 2.5;
                });

                if (!linha) {
                    linha = { y: y, itens: [] };
                    linhas.push(linha);
                }

                linha.itens.push({ x: item.transform[4], texto: String(item.str).trim() });
            });

        return linhas.map(function (linha) {
            return linha.itens
                .sort(function (a, b) { return a.x - b.x; })
                .map(function (item) { return item.texto; })
                .join(' ')
                .replace(/\s+/g, ' ')
                .trim();
        });
    }

    function registrosDasLinhas(linhas, mesSelecionado) {
        const encontrados = new Map();

        linhas.forEach(function (linha) {
            const data = dataDaLinha(linha, mesSelecionado);
            const horarios = Array.from(linha.matchAll(/\b(?:[01]?\d|2[0-3])[:h][0-5]\d\b/gi))
                .map(function (resultado) {
                    const partes = resultado[0].toLowerCase().replace('h', ':').split(':');
                    return String(Number(partes[0])).padStart(2, '0') + ':' + partes[1];
                })
                .slice(0, 4);

            if (!data || horarios.length === 0) {
                return;
            }

            const anterior = encontrados.get(data);
            if (!anterior || horarios.length > anterior.horarios.length) {
                encontrados.set(data, { data: data, horarios: horarios });
            }
        });

        return Array.from(encontrados.values())
            .sort(function (a, b) { return a.data.localeCompare(b.data); })
            .map(function (item) {
                return {
                    data: item.data,
                    entrada_1: item.horarios[0] || '',
                    saida_1: item.horarios[1] || '',
                    entrada_2: item.horarios[2] || '',
                    saida_2: item.horarios[3] || ''
                };
            });
    }

    function mostrarErroPdf(mensagem) {
        const erro = document.getElementById('erroImportacaoPdf');
        if (!erro) return;
        erro.textContent = mensagem;
        erro.classList.remove('d-none');
    }

    function limparImportacaoPdf() {
        document.getElementById('erroImportacaoPdf')?.classList.add('d-none');
        document.getElementById('previewImportacaoPdf')?.classList.add('d-none');
        const oculto = document.getElementById('registrosPdf');
        const confirmar = document.getElementById('btnConfirmarImportacaoPdf');
        const corpo = document.getElementById('corpoImportacaoPdf');
        if (oculto) oculto.value = '';
        if (confirmar) confirmar.disabled = true;
        if (corpo) corpo.replaceChildren();
    }

    function formatarDataBr(dataIso) {
        const partes = dataIso.split('-');
        return partes[2] + '/' + partes[1] + '/' + partes[0];
    }

    function mostrarPreviewPdf(registros) {
        const corpo = document.getElementById('corpoImportacaoPdf');
        const preview = document.getElementById('previewImportacaoPdf');
        const quantidade = document.getElementById('quantidadeImportacaoPdf');
        const oculto = document.getElementById('registrosPdf');
        const confirmar = document.getElementById('btnConfirmarImportacaoPdf');

        if (!corpo || !preview || !oculto || !confirmar) return;
        corpo.replaceChildren();

        registros.forEach(function (registro) {
            const linha = document.createElement('tr');
            [formatarDataBr(registro.data), registro.entrada_1, registro.saida_1, registro.entrada_2, registro.saida_2]
                .forEach(function (valor) {
                    const coluna = document.createElement('td');
                    coluna.textContent = valor || '-';
                    linha.appendChild(coluna);
                });
            corpo.appendChild(linha);
        });

        oculto.value = JSON.stringify(registros);
        confirmar.disabled = false;
        if (quantidade) quantidade.textContent = registros.length + (registros.length === 1 ? ' dia' : ' dias');
        preview.classList.remove('d-none');
    }

    async function lerPdf() {
        limparImportacaoPdf();
        const arquivo = document.getElementById('arquivoPontoPdf');
        const status = document.getElementById('statusImportacaoPdf');
        const mes = document.querySelector('#formImportarPdf input[name="mes"]')?.value || '';

        if (!arquivo?.files?.[0]) {
            arquivo?.classList.add('is-invalid');
            mostrarErroPdf('Selecione o PDF antes de iniciar a leitura.');
            return;
        }

        arquivo.classList.remove('is-invalid');
        if (arquivo.files[0].size > 15 * 1024 * 1024) {
            mostrarErroPdf('O PDF deve ter no máximo 15 MB.');
            return;
        }

        if (!window.pdfjsLib) {
            mostrarErroPdf('O leitor de PDF não carregou. Verifique a internet e tente novamente.');
            return;
        }

        status?.classList.remove('d-none');

        try {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            const bytes = new Uint8Array(await arquivo.files[0].arrayBuffer());
            const pdf = await window.pdfjsLib.getDocument({ data: bytes }).promise;
            const linhas = [];
            let totalItens = 0;

            for (let paginaNumero = 1; paginaNumero <= pdf.numPages; paginaNumero += 1) {
                const pagina = await pdf.getPage(paginaNumero);
                const conteudo = await pagina.getTextContent();
                totalItens += conteudo.items.length;
                linhas.push.apply(linhas, linhasDoPdf(conteudo.items));
            }

            if (totalItens === 0) {
                throw new Error('Este PDF parece ser uma imagem digitalizada. Para ele, será necessário OCR; preencha manualmente por enquanto.');
            }

            const registros = registrosDasLinhas(linhas, mes);
            if (registros.length === 0) {
                throw new Error('Não encontrei datas e horários do mês selecionado. Confira se o PDF contém texto e se o mês está correto.');
            }

            mostrarPreviewPdf(registros);
        } catch (erro) {
            mostrarErroPdf(erro?.message || 'Não foi possível ler este PDF.');
        } finally {
            status?.classList.add('d-none');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.alerta-temporario').forEach(function (alerta) {
            window.setTimeout(function () {
                alerta.style.transition = 'opacity .25s ease';
                alerta.style.opacity = '0';
                window.setTimeout(function () { alerta.remove(); }, 260);
            }, 4000);
        });

        document.getElementById('funcionarioPonto')?.addEventListener('change', function () {
            document.getElementById('formFiltrosPonto')?.submit();
        });

        document.getElementById('mesPonto')?.addEventListener('change', function () {
            document.getElementById('formMesPonto')?.submit();
        });

        document.getElementById('btnImprimirPonto')?.addEventListener('click', function () {
            window.print();
        });

        const modalFuncionario = document.getElementById('modalFuncionario');
        modalFuncionario?.addEventListener('show.bs.modal', function (evento) {
            const botao = evento.relatedTarget;
            let horarios = null;

            if (botao?.dataset.horarios) {
                try {
                    horarios = JSON.parse(botao.dataset.horarios);
                } catch (erro) {
                    horarios = null;
                }
            }

            preencherModalFuncionario({
                id: botao?.dataset.id || '',
                nome: botao?.dataset.nome || '',
                ativo: Number(botao?.dataset.ativo ?? 1),
                horarios: horarios
            });
        });

        document.querySelectorAll('.jornada-trabalha, .jornada-hora').forEach(function (campo) {
            campo.addEventListener('change', atualizarCargaSemanal);
            campo.addEventListener('input', atualizarCargaSemanal);
        });

        document.getElementById('formFuncionario')?.addEventListener('submit', validarFuncionario);

        document.getElementById('btnExcluirFuncionario')?.addEventListener('click', function () {
            const id = document.getElementById('funcionarioIdModal')?.value || '';
            const nome = document.getElementById('funcionarioNome')?.value || 'este funcionário';
            const excluirId = document.getElementById('funcionarioExcluirId');
            const excluirNome = document.getElementById('funcionarioExcluirNome');
            const modalEdicao = bootstrap.Modal.getInstance(document.getElementById('modalFuncionario'));
            const modalExclusaoElemento = document.getElementById('modalExcluirFuncionario');

            if (!id || !modalExclusaoElemento) {
                return;
            }

            if (excluirId) excluirId.value = id;
            if (excluirNome) excluirNome.textContent = nome;
            modalEdicao?.hide();

            window.setTimeout(function () {
                bootstrap.Modal.getOrCreateInstance(modalExclusaoElemento).show();
            }, 180);
        });

        document.querySelectorAll('.ponto-hora-registro').forEach(function (campo) {
            campo.addEventListener('input', function () { atualizarLinhaRegistro(campo); });
            campo.addEventListener('change', function () { atualizarLinhaRegistro(campo); });
        });

        const modalPdf = document.getElementById('modalImportarPdf');
        modalPdf?.addEventListener('hidden.bs.modal', function () {
            document.getElementById('formImportarPdf')?.reset();
            limparImportacaoPdf();
        });

        document.getElementById('arquivoPontoPdf')?.addEventListener('change', limparImportacaoPdf);
        document.getElementById('btnLerPdf')?.addEventListener('click', lerPdf);
        document.getElementById('formImportarPdf')?.addEventListener('submit', function (evento) {
            if (!document.getElementById('registrosPdf')?.value) {
                evento.preventDefault();
                mostrarErroPdf('Leia e confira o PDF antes de confirmar a importação.');
            }
        });
    });
}());