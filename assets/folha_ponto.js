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
    let registrosPdfAtuais = [];
    let registrosTextoReconhecido = [];
    let camposDetectadosPdf = new Set();
    let linhasInvalidasPdf = new Set();

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

    function atualizarLinhaRegistro(referencia) {
        const linha = referencia?.matches?.('.ponto-registro-linha')
            ? referencia
            : referencia?.closest?.('.ponto-registro-linha');

        if (!linha) {
            return null;
        }

        const camposHorario = Array.from(linha.querySelectorAll('.ponto-hora-registro'));
        const horarios = camposHorario.map(function (item) {
            return item.value;
        });
        const total = linha.querySelector('.ponto-total-dia');
        const saldo = linha.querySelector('.ponto-saldo-dia');
        const status = linha.querySelector('.ponto-status-dia');
        const previstoTexto = linha.querySelector('.ponto-previsto');
        const observacao = linha.querySelector('.ponto-observacao')?.value.trim() || '';
        const atestado = linha.querySelector('.ponto-atestado-check')?.checked === true;
        const trabalhado = atestado
            ? 0
            : minutosIntervalo(horarios[0], horarios[1]) + minutosIntervalo(horarios[2], horarios[3]);
        const feriadoInformado = /^feriado\b/i.test(observacao);
        const folgaInformada = /^folga\b/i.test(observacao);
        const feriadoNacional = linha.dataset.feriadoNacional === '1';
        const feriado = feriadoNacional || feriadoInformado;
        const descricaoInformada = feriadoInformado
            ? observacao.replace(/^feriado\s*:?\s*/i, '').trim()
            : '';
        const feriadoNome = descricaoInformada || linha.dataset.feriadoNome || '';
        const previstoJornada = Number(total?.dataset.previstoJornada || total?.dataset.previsto || 0);
        const previsto = feriado || folgaInformada || atestado ? 0 : previstoJornada;
        const possuiMarcacao = horarios.some(Boolean);
        const incompleto = possuiMarcacao && (
            !horarios[0]
            || !horarios[1]
            || ((horarios[2] || horarios[3]) && (!horarios[2] || !horarios[3]))
        );
        const hoje = document.getElementById('formRegistrosPonto')?.dataset.hoje || '';
        const data = linha.dataset.data || '';
        let statusTexto = 'Registrado';
        let statusClasse = 'bg-success';

        if (total) {
            total.textContent = formatarMinutos(trabalhado, false);
            total.dataset.previsto = String(previsto);
        }
        if (saldo) {
            const diferenca = trabalhado - previsto;
            saldo.textContent = formatarMinutos(diferenca, true);
            saldo.classList.toggle('text-danger', diferenca < 0);
            saldo.classList.toggle('text-success', diferenca >= 0);
        }

        linha.classList.toggle('ponto-dia-folga', previsto <= 0);
        linha.classList.toggle('ponto-dia-atestado', atestado);
        linha.classList.toggle('ponto-dia-feriado', feriado);

        if (previstoTexto) {
            previstoTexto.textContent = feriado
                ? 'Feriado' + (feriadoNome ? ': ' + feriadoNome : '')
                : (atestado ? 'Atestado' : (linha.dataset.jornadaPrevista || 'Folga'));
        }

        if (feriado) {
            statusTexto = 'Feriado';
            statusClasse = 'bg-primary';
        } else if (atestado) {
            statusTexto = 'Atestado';
            statusClasse = 'bg-info text-dark';
        } else if (folgaInformada) {
            statusTexto = 'Folga';
            statusClasse = 'bg-secondary';
        } else if (!possuiMarcacao && previsto <= 0) {
            statusTexto = 'Folga';
            statusClasse = 'bg-secondary';
        } else if (!possuiMarcacao && data > hoje) {
            statusTexto = 'Aguardando';
            statusClasse = 'bg-light text-dark border';
        } else if (!possuiMarcacao) {
            statusTexto = 'Sem registro';
            statusClasse = 'bg-danger';
        } else if (incompleto) {
            statusTexto = 'Incompleto';
            statusClasse = 'bg-warning text-dark';
        }

        if (status) {
            status.textContent = statusTexto;
            status.className = 'badge ponto-status-dia ' + statusClasse;
        }

        return {
            data: data,
            previsto: previsto,
            trabalhado: trabalhado,
            semRegistro: !possuiMarcacao && previsto > 0 && data <= hoje
        };
    }

    function atualizarResumoRegistros() {
        const form = document.getElementById('formRegistrosPonto');
        const hoje = form?.dataset.hoje || '';
        let totalTrabalhadoMes = 0;
        let totalPrevistoAteHoje = 0;
        let totalTrabalhadoAteHoje = 0;
        let diasSemRegistro = 0;

        document.querySelectorAll('.ponto-registro-linha').forEach(function (linha) {
            const resultado = atualizarLinhaRegistro(linha);
            if (!resultado) return;

            totalTrabalhadoMes += resultado.trabalhado;
            if (resultado.data <= hoje) {
                totalPrevistoAteHoje += resultado.previsto;
                totalTrabalhadoAteHoje += resultado.trabalhado;
            }
            if (resultado.semRegistro) diasSemRegistro += 1;
        });

        const horasRegistradas = document.getElementById('totalHorasRegistradas');
        const saldoAteHoje = document.getElementById('totalSaldoAteHoje');
        const semRegistro = document.getElementById('totalDiasSemRegistro');
        const metricaSaldo = document.getElementById('metricaSaldoAteHoje');
        const metricaSemRegistro = document.getElementById('metricaDiasSemRegistro');
        const saldo = totalTrabalhadoAteHoje - totalPrevistoAteHoje;

        if (horasRegistradas) horasRegistradas.textContent = formatarMinutos(totalTrabalhadoMes, false);
        if (saldoAteHoje) saldoAteHoje.textContent = formatarMinutos(saldo, true);
        if (semRegistro) semRegistro.textContent = String(diasSemRegistro);

        metricaSaldo?.classList.toggle('metrica-negativa', saldo < 0);
        metricaSaldo?.classList.toggle('metrica-positiva', saldo >= 0);
        metricaSemRegistro?.classList.toggle('metrica-negativa', diasSemRegistro > 0);
        metricaSemRegistro?.classList.toggle('metrica-positiva', diasSemRegistro === 0);
    }

    function dataIsoValida(ano, mes, dia) {
        const data = new Date(Date.UTC(ano, mes - 1, dia));
        return data.getUTCFullYear() === ano
            && data.getUTCMonth() === mes - 1
            && data.getUTCDate() === dia;
    }

    function textoNormalizado(texto) {
        return String(texto || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
    }

    function mesDoTextoPdf(texto) {
        const normalizado = textoNormalizado(texto);
        const meses = {
            janeiro: '01', jan: '01', fevereiro: '02', fev: '02', marco: '03', mar: '03',
            abril: '04', abr: '04', maio: '05', mai: '05', junho: '06', jun: '06',
            julho: '07', jul: '07', agosto: '08', ago: '08', setembro: '09', set: '09',
            outubro: '10', out: '10', novembro: '11', nov: '11', dezembro: '12', dez: '12'
        };
        const periodo = normalizado.match(/(?:periodo|competencia|de)\s*:?\s*[0-3]?\d\s*[\/.\-]\s*([01]?\d)\s*[\/.\-]\s*(20\d{2})\s*(?:ate|a|-)\s*[0-3]?\d\s*[\/.\-]\s*[01]?\d\s*[\/.\-]\s*20\d{2}/);
        const periodoDireto = normalizado.match(/(?:^|\D)[0-3]?\d\s*[\/.\-]\s*([01]?\d)\s*[\/.\-]\s*(20\d{2})\s*(?:ate|a|-)\s*[0-3]?\d\s*[\/.\-]\s*[01]?\d\s*[\/.\-]\s*20\d{2}(?!\d)/);

        if (periodo || periodoDireto) {
            const encontrado = periodo || periodoDireto;
            return encontrado[2] + '-' + String(Number(encontrado[1])).padStart(2, '0');
        }

        const frequencias = new Map();
        Array.from(normalizado.matchAll(/(?:^|\D)[0-3]?\d\s*[\/.\-]\s*([01]?\d)\s*[\/.\-]\s*(20\d{2})(?!\d)/g)).forEach(function (data) {
            const chave = data[2] + '-' + String(Number(data[1])).padStart(2, '0');
            frequencias.set(chave, (frequencias.get(chave) || 0) + 1);
        });

        if (frequencias.size > 0) {
            return Array.from(frequencias.entries()).sort(function (a, b) { return b[1] - a[1]; })[0][0];
        }

        for (const [nome, numero] of Object.entries(meses)) {
            const mesDepoisAno = normalizado.match(new RegExp('\\b' + nome + '\\b[\\s\\S]{0,80}?\\b(20\\d{2})\\b'));
            const anoAntesMes = normalizado.match(new RegExp('\\b(20\\d{2})\\b[\\s\\S]{0,40}?\\b' + nome + '\\b'));
            const ano = mesDepoisAno?.[1] || anoAntesMes?.[1] || '';
            if (ano) return ano + '-' + numero;
        }

        return '';
    }

    function nomeMesIso(mesIso) {
        const partes = mesIso.split('-').map(Number);
        if (partes.length !== 2 || !partes[0] || !partes[1]) return mesIso;
        const texto = new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' })
            .format(new Date(partes[0], partes[1] - 1, 1, 12));
        return texto.charAt(0).toUpperCase() + texto.slice(1);
    }

    function definirMesImportacao(mesDetectado) {
        const campoMes = document.querySelector('#formImportarPdf input[name="mes"]');
        const aviso = document.getElementById('avisoMesImportacaoPdf');
        const tituloMes = document.getElementById('mesImportacaoPdf');
        const mesOriginal = campoMes?.dataset.mesOriginal || campoMes?.value || '';
        const mesFinal = mesDetectado || mesOriginal;

        if (campoMes) campoMes.value = mesFinal;
        if (tituloMes) tituloMes.textContent = nomeMesIso(mesFinal);
        if (aviso) {
            aviso.textContent = mesFinal !== mesOriginal
                ? 'O arquivo é de ' + nomeMesIso(mesFinal) + '. Ele será importado diretamente nesse mês.'
                : '';
            aviso.classList.toggle('d-none', mesFinal === mesOriginal);
        }

        return mesFinal;
    }

    function possuiDiaSemana(texto) {
        return /\b(?:dom(?:ingo)?|seg(?:unda(?:-feira)?)?|ter(?:ca(?:-feira)?)?|qua(?:rta(?:-feira)?)?|qui(?:nta(?:-feira)?)?|sex(?:ta(?:-feira)?)?|sab(?:ado)?)\b/i.test(textoNormalizado(texto));
    }

    function horariosDaLinha(texto) {
        return Array.from(String(texto || '').matchAll(/(?:^|[^\d])([01]?\d|2[0-3])\s*[:h.,]\s*([0-5]\d)(?::[0-5]\d)?(?!\d)(?!\s*[.\/]\s*\d)/gi))
            .map(function (resultado) {
                return String(Number(resultado[1])).padStart(2, '0') + ':' + resultado[2];
            });
    }

    function horariosComMarcadorDaLinha(texto) {
        return Array.from(String(texto || '').matchAll(/(?:^|[^\d])([01]?\d|2[0-3])\s*[:h.,]?\s*([0-5]\d)\s*\(\s*[^)\s]{0,2}\s*\)?/gi))
            .map(function (resultado) {
                return String(Number(resultado[1])).padStart(2, '0') + ':' + resultado[2];
            });
    }

    function removerTotalCalculado(horarios) {
        if (horarios.length !== 3 && horarios.length !== 5) return horarios;

        const marcacoes = horarios.slice(0, -1);
        const total = minutosIntervalo(marcacoes[0], marcacoes[1])
            + minutosIntervalo(marcacoes[2], marcacoes[3]);
        return minutosHora(horarios[horarios.length - 1]) === total ? marcacoes : horarios;
    }

    function horariosRealizadosDaLinha(texto, possuiColunaPrevisto) {
        const marcados = horariosComMarcadorDaLinha(texto);
        if (marcados.length >= 2) return marcados.slice(0, 4);

        let textoRealizado = String(texto || '');
        if (possuiColunaPrevisto) {
            const horario = '(?:[01]?\\d|2[0-3])\\s*[:h.,]\\s*[0-5]\\d';
            textoRealizado = textoRealizado.replace(new RegExp(horario + '\\s*[-–—]\\s*' + horario, 'gi'), ' ');
        }

        return removerTotalCalculado(horariosDaLinha(textoRealizado)).slice(0, 4);
    }

    function observacaoEspecialDaLinha(texto) {
        const normalizado = textoNormalizado(texto);
        if (/\batestado\b/i.test(normalizado)) {
            const descricaoAtestado = String(texto || '').match(/atestado\s*:\s*(.+)$/i);
            return descricaoAtestado?.[1]?.trim() ? 'Atestado: ' + descricaoAtestado[1].trim() : 'Atestado';
        }
        if (/\bfolga\b/i.test(normalizado)) return 'Folga';
        if (!/\bferiado\b/i.test(normalizado)) return '';

        const descricao = String(texto || '').match(/feriado\s*:\s*(.+)$/i);
        return descricao?.[1]?.trim() ? 'Feriado: ' + descricao[1].trim() : 'Feriado';
    }

    function dataDaLinha(texto, mesSelecionado) {
        const partesMes = mesSelecionado.split('-').map(Number);
        let ano = partesMes[0];
        let mes = partesMes[1];
        let dia = null;
        const dataIso = texto.match(/(?:^|\D)(\d{4})-([01]?\d)-([0-3]?\d)(?!\d)/);
        const dataCompleta = texto.match(/(?:^|\D)([0-3]?\d)\s*[\/.\-]\s*([01]?\d)(?:\s*[\/.\-]\s*(\d{2}|\d{4}))?(?!\d)/);
        const textoBusca = textoNormalizado(texto);

        if (dataIso) {
            ano = Number(dataIso[1]);
            mes = Number(dataIso[2]);
            dia = Number(dataIso[3]);
        } else if (dataCompleta) {
            dia = Number(dataCompleta[1]);
            mes = Number(dataCompleta[2]);
            if (dataCompleta[3]) {
                ano = Number(dataCompleta[3]);
                if (ano < 100) ano += 2000;
            }
        } else {
            const somenteDia = textoBusca.match(/^\s*([0-3]?\d)(?=\s|$|\|)/);
            const diaDepoisDaSemana = textoBusca.match(/\b(?:dom(?:ingo)?|seg(?:unda(?:-feira)?)?|ter(?:ca(?:-feira)?)?|qua(?:rta(?:-feira)?)?|qui(?:nta(?:-feira)?)?|sex(?:ta(?:-feira)?)?|sab(?:ado)?)\.?\s*[,]?\s*([0-3]?\d)(?!\d)/i);
            dia = somenteDia ? Number(somenteDia[1]) : (diaDepoisDaSemana ? Number(diaDepoisDaSemana[1]) : null);
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
                return Math.abs(diferencaY) > 4 ? diferencaY : a.transform[4] - b.transform[4];
            })
            .forEach(function (item) {
                const y = item.transform[5];
                let linha = linhas.find(function (existente) {
                    return Math.abs(existente.y - y) <= 4;
                });

                if (!linha) {
                    linha = { y: y, itens: [] };
                    linhas.push(linha);
                }

                linha.itens.push({ x: item.transform[4], texto: String(item.str).trim() });
            });

        return linhas.flatMap(function (linha) {
            const texto = linha.itens
                .sort(function (a, b) { return a.x - b.x; })
                .map(function (item) { return item.texto; })
                .join(' ')
                .replace(/\s+/g, ' ')
                .trim();
            return texto.split(/[\r\n]+/).map(function (parte) { return parte.trim(); }).filter(Boolean);
        });
    }

    function registrosDasLinhas(linhas, mesSelecionado) {
        const encontrados = new Map();
        const possuiColunaPrevisto = /\b(?:previsto|prevista|programado|programada)\b/.test(textoNormalizado(linhas.join(' ')));

        linhas.forEach(function (linha, indice) {
            const data = dataDaLinha(linha, mesSelecionado);
            const folga = /\bfolga\b/i.test(textoNormalizado(linha));
            const atestado = /\batestado\b/i.test(textoNormalizado(linha));
            const semMarcacao = folga || atestado;
            let textoRegistro = linha;
            let horarios = semMarcacao ? [] : horariosRealizadosDaLinha(textoRegistro, possuiColunaPrevisto);

            if (data && !semMarcacao && horarios.length < 4) {
                for (let proximoIndice = indice + 1; proximoIndice < Math.min(linhas.length, indice + 3); proximoIndice += 1) {
                    const proximaLinha = linhas[proximoIndice];
                    if (dataDaLinha(proximaLinha, mesSelecionado)) break;
                    textoRegistro += ' ' + proximaLinha;
                    horarios = horariosRealizadosDaLinha(textoRegistro, possuiColunaPrevisto);
                    if (horarios.length >= 4) break;
                }
            }

            horarios = horarios.slice(0, 4);
            const observacao = observacaoEspecialDaLinha(textoRegistro);
            const identificadaComoDia = Boolean(data) && (
                possuiDiaSemana(textoRegistro)
                || /(?:^|\D)[0-3]?\d\s*[\/.\-]\s*[01]?\d/.test(textoRegistro)
                || /(?:^|\D)\d{4}-[01]?\d-[0-3]?\d(?!\d)/.test(textoRegistro)
                || /^\s*[0-3]?\d(?=\s|$|\|)/.test(textoRegistro)
            );

            if (!identificadaComoDia || (horarios.length === 0 && observacao === '')) {
                return;
            }

            const anterior = encontrados.get(data);
            if (!anterior || horarios.length > anterior.horarios.length) {
                encontrados.set(data, { data: data, horarios: horarios, observacao: observacao });
            } else if (observacao && !anterior.observacao) {
                anterior.observacao = observacao;
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
                    saida_2: item.horarios[3] || '',
                    observacao: item.observacao || ''
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
        document.getElementById('etapaTextoImportacaoPdf')?.classList.add('d-none');
        document.getElementById('previewImportacaoPdf')?.classList.add('d-none');
        document.getElementById('avisoRevisaoOcr')?.classList.add('d-none');
        document.getElementById('avisoMesImportacaoPdf')?.classList.add('d-none');
        const oculto = document.getElementById('registrosPdf');
        const confirmar = document.getElementById('btnConfirmarImportacaoPdf');
        const corpo = document.getElementById('corpoImportacaoPdf');
        const textoReconhecido = document.getElementById('textoReconhecidoPdf');
        const voltarTexto = document.getElementById('btnVoltarTextoPdf');
        const textoStatus = document.getElementById('statusImportacaoTexto');
        const campoMes = document.querySelector('#formImportarPdf input[name="mes"]');
        registrosPdfAtuais = [];
        registrosTextoReconhecido = [];
        camposDetectadosPdf = new Set();
        linhasInvalidasPdf = new Set();
        if (oculto) oculto.value = '';
        if (confirmar) confirmar.disabled = true;
        if (corpo) corpo.replaceChildren();
        if (textoReconhecido) {
            textoReconhecido.value = '';
            textoReconhecido.classList.remove('is-invalid');
        }
        voltarTexto?.classList.add('d-none');
        if (textoStatus) textoStatus.textContent = 'Lendo o arquivo...';
        if (campoMes?.dataset.mesOriginal) definirMesImportacao(campoMes.dataset.mesOriginal);
    }

    function formatarDataBr(dataIso) {
        const partes = dataIso.split('-');
        return partes[2] + '/' + partes[1] + '/' + partes[0];
    }

    function nomeDiaSemana(dataIso) {
        const partes = dataIso.split('-').map(Number);
        const nomes = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        return nomes[new Date(partes[0], partes[1] - 1, partes[2], 12).getDay()];
    }

    function sincronizarRegistrosPdf() {
        const oculto = document.getElementById('registrosPdf');
        const confirmar = document.getElementById('btnConfirmarImportacaoPdf');
        const algumRegistro = registrosPdfAtuais.some(function (registro) {
            return registro.observacao !== '' || ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'].some(function (campo) {
                return registro[campo] !== '';
            });
        });
        const horariosValidos = registrosPdfAtuais.every(function (registro) {
            return ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'].every(function (campo) {
                return registro[campo] === '' || /^([01]\d|2[0-3]):[0-5]\d$/.test(registro[campo]);
            });
        });
        const estruturaValida = linhasInvalidasPdf.size === 0;
        if (oculto) oculto.value = horariosValidos && estruturaValida && algumRegistro ? JSON.stringify(registrosPdfAtuais) : '';
        if (confirmar) confirmar.disabled = registrosPdfAtuais.length === 0 || !horariosValidos || !estruturaValida || !algumRegistro;
    }

    function normalizarHorarioDigitado(valor) {
        const texto = String(valor || '').trim();
        const horarioCompleto = texto.match(/^(\d{1,2})\s*[:h]\s*(\d{1,2})?$/i);
        let hora = null;
        let minuto = 0;

        if (horarioCompleto) {
            hora = Number(horarioCompleto[1]);
            minuto = horarioCompleto[2] ? Number(horarioCompleto[2].padEnd(2, '0')) : 0;
        } else {
            const digitos = texto.replace(/\D/g, '');

            if (digitos.length >= 1 && digitos.length <= 2) {
                hora = Number(digitos);
            } else if (digitos.length === 3) {
                hora = Number(digitos.slice(0, 1));
                minuto = Number(digitos.slice(1));
            } else if (digitos.length === 4) {
                hora = Number(digitos.slice(0, 2));
                minuto = Number(digitos.slice(2));
            }
        }

        if (hora === null || hora > 23 || minuto > 59) return '';
        return String(hora).padStart(2, '0') + ':' + String(minuto).padStart(2, '0');
    }

    function preencherSituacaoPreview(coluna, observacao, revisar) {
        coluna.replaceChildren();
        const etiqueta = document.createElement('span');

        if (revisar) {
            coluna.textContent = '-';
            return;
        }

        if (/^feriado\b/i.test(observacao)) {
            etiqueta.className = 'badge bg-primary';
            etiqueta.textContent = observacao;
        } else if (/^atestado\b/i.test(observacao)) {
            etiqueta.className = 'badge bg-info text-dark';
            etiqueta.textContent = observacao;
        } else if (/^folga\b/i.test(observacao)) {
            etiqueta.className = 'badge bg-secondary';
            etiqueta.textContent = 'Folga';
        } else {
            coluna.textContent = '-';
            return;
        }

        coluna.appendChild(etiqueta);
    }

    function validarRegistroPreview(indice, registro, linha, colunaSituacao, inputs) {
        const camposInvalidos = new Set();
        const campos = ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'];
        const especial = /^(?:feriado|folga|atestado)\b/i.test(registro.observacao);

        if (!especial) {
            campos.forEach(function (campo) {
                if (inputs[campo]?.dataset.revisarOcr === '1') {
                    camposInvalidos.add(campo);
                }
                if (registro[campo] !== '' && minutosHora(registro[campo]) === null) {
                    camposInvalidos.add(campo);
                }
            });

            const primeiroParIncompleto = Boolean(registro.entrada_1) !== Boolean(registro.saida_1);
            const segundoParIncompleto = Boolean(registro.entrada_2) !== Boolean(registro.saida_2);

            if (primeiroParIncompleto) {
                camposInvalidos.add('entrada_1');
                camposInvalidos.add('saida_1');
            }
            if (segundoParIncompleto) {
                camposInvalidos.add('entrada_2');
                camposInvalidos.add('saida_2');
            }
            if ((registro.entrada_2 || registro.saida_2) && (!registro.entrada_1 || !registro.saida_1)) {
                campos.forEach(function (campo) { camposInvalidos.add(campo); });
            }
            if (registro.entrada_1 && registro.saida_1 && minutosIntervalo(registro.entrada_1, registro.saida_1) <= 0) {
                camposInvalidos.add('entrada_1');
                camposInvalidos.add('saida_1');
            }
            if (registro.entrada_2 && registro.saida_2 && minutosIntervalo(registro.entrada_2, registro.saida_2) <= 0) {
                camposInvalidos.add('entrada_2');
                camposInvalidos.add('saida_2');
            }
            if (
                registro.saida_1
                && registro.entrada_2
                && minutosHora(registro.entrada_2) < minutosHora(registro.saida_1)
            ) {
                camposInvalidos.add('saida_1');
                camposInvalidos.add('entrada_2');
            }
        }

        campos.forEach(function (campo) {
            inputs[campo]?.classList.toggle('ponto-preview-hora-invalida', camposInvalidos.has(campo));
        });

        const invalido = camposInvalidos.size > 0;
        linha.classList.toggle('ponto-preview-linha-invalida', invalido);
        if (invalido) linhasInvalidasPdf.add(indice);
        else linhasInvalidasPdf.delete(indice);
        preencherSituacaoPreview(colunaSituacao, registro.observacao, invalido);
        return !invalido;
    }

    function textoEstruturadoDosRegistros(registros) {
        const cabecalho = 'DATA | ENTRADA | ALMOCO | RETORNO | SAIDA | SITUACAO';
        const linhas = registros.map(function (registro) {
            const campos = ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'].map(function (campo) {
                return registro._revisar?.[campo] ? '?' : (registro[campo] || '');
            });
            const observacao = String(registro.observacao || '').replace(/\|/g, '/');
            return [formatarDataBr(registro.data)].concat(campos, observacao).join(' | ');
        });
        return [cabecalho].concat(linhas).join('\n');
    }

    function dataDoTextoReconhecido(valor) {
        const texto = String(valor || '').trim();
        const brasileira = texto.match(/^([0-3]?\d)\s*[\/.\-]\s*([01]?\d)\s*[\/.\-]\s*(20\d{2})$/);
        const iso = texto.match(/^(20\d{2})-([01]?\d)-([0-3]?\d)$/);
        let ano;
        let mes;
        let dia;

        if (brasileira) {
            dia = Number(brasileira[1]);
            mes = Number(brasileira[2]);
            ano = Number(brasileira[3]);
        } else if (iso) {
            ano = Number(iso[1]);
            mes = Number(iso[2]);
            dia = Number(iso[3]);
        } else {
            return '';
        }

        return dataIsoValida(ano, mes, dia)
            ? ano + '-' + String(mes).padStart(2, '0') + '-' + String(dia).padStart(2, '0')
            : '';
    }

    function registrosDoTextoReconhecido(texto) {
        const originais = new Map(registrosTextoReconhecido.map(function (registro) {
            return [registro.data, registro];
        }));
        const registros = [];
        const erros = [];

        String(texto || '').split(/[\r\n]+/).forEach(function (linha, indice) {
            const limpa = linha.trim();
            if (!limpa || /^data\s*\|/i.test(limpa)) return;
            const colunas = limpa.split('|').map(function (coluna) { return coluna.trim(); });
            if (colunas.length !== 6) {
                erros.push('Linha ' + (indice + 1) + ': use seis colunas separadas por |.');
                return;
            }

            const data = dataDoTextoReconhecido(colunas[0]);
            if (!data) {
                erros.push('Linha ' + (indice + 1) + ': data inválida.');
                return;
            }

            const original = originais.get(data) || {};
            const registro = {
                data: data,
                entrada_1: '',
                saida_1: '',
                entrada_2: '',
                saida_2: '',
                observacao: colunas[5],
                _imagens: original._imagens || {},
                _revisar: {}
            };

            ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'].forEach(function (campo, campoIndice) {
                const valor = colunas[campoIndice + 1];
                if (valor === '?') {
                    registro._revisar[campo] = true;
                    return;
                }
                if (!valor) {
                    registro._revisar[campo] = false;
                    return;
                }
                const horario = normalizarHorarioDigitado(valor);
                if (!horario) {
                    erros.push('Linha ' + (indice + 1) + ': horário "' + valor + '" inválido.');
                    return;
                }
                registro[campo] = horario;
                registro._revisar[campo] = false;
            });

            if (/^(?:folga|atestado|feriado)\b/i.test(registro.observacao)) {
                registro.entrada_1 = '';
                registro.saida_1 = '';
                registro.entrada_2 = '';
                registro.saida_2 = '';
                registro._revisar = {};
            }
            registros.push(registro);
        });

        const datas = new Set();
        registros.forEach(function (registro) {
            if (datas.has(registro.data)) erros.push('A data ' + formatarDataBr(registro.data) + ' está repetida.');
            datas.add(registro.data);
        });

        registros.sort(function (a, b) { return a.data.localeCompare(b.data); });
        return { registros: registros, erros: erros };
    }

    function mostrarTextoReconhecido(registros) {
        const etapa = document.getElementById('etapaTextoImportacaoPdf');
        const campo = document.getElementById('textoReconhecidoPdf');
        const quantidade = document.getElementById('quantidadeTextoImportacaoPdf');
        const preview = document.getElementById('previewImportacaoPdf');
        const confirmar = document.getElementById('btnConfirmarImportacaoPdf');
        if (!etapa || !campo) return;

        registrosTextoReconhecido = registros;
        campo.value = textoEstruturadoDosRegistros(registros);
        campo.classList.remove('is-invalid');
        if (quantidade) quantidade.textContent = registros.length + (registros.length === 1 ? ' dia' : ' dias');
        preview?.classList.add('d-none');
        etapa.classList.remove('d-none');
        if (confirmar) confirmar.disabled = true;
    }

    function aplicarTextoReconhecido() {
        const campo = document.getElementById('textoReconhecidoPdf');
        const erro = document.getElementById('erroImportacaoPdf');
        if (!campo) return;
        const resultado = registrosDoTextoReconhecido(campo.value);

        if (resultado.erros.length > 0 || resultado.registros.length === 0) {
            campo.classList.add('is-invalid');
            mostrarErroPdf(resultado.erros[0] || 'Nenhum dia válido foi encontrado no texto reconhecido.');
            return;
        }

        campo.classList.remove('is-invalid');
        erro?.classList.add('d-none');
        document.getElementById('etapaTextoImportacaoPdf')?.classList.add('d-none');
        mostrarPreviewPdf(resultado.registros, true);
    }

    function mostrarPreviewPdf(registros, leituraOcr) {
        const corpo = document.getElementById('corpoImportacaoPdf');
        const preview = document.getElementById('previewImportacaoPdf');
        const quantidade = document.getElementById('quantidadeImportacaoPdf');
        const avisoOcr = document.getElementById('avisoRevisaoOcr');
        const voltarTexto = document.getElementById('btnVoltarTextoPdf');

        if (!corpo || !preview) return;
        corpo.replaceChildren();
        camposDetectadosPdf = new Set();
        linhasInvalidasPdf = new Set();
        registrosPdfAtuais = registros.map(function (registro) {
            return {
                data: registro.data,
                entrada_1: registro.entrada_1 || '',
                saida_1: registro.saida_1 || '',
                entrada_2: registro.entrada_2 || '',
                saida_2: registro.saida_2 || '',
                observacao: registro.observacao || ''
            };
        });

        registrosPdfAtuais.forEach(function (registro, indice) {
            const registroOriginal = registros[indice] || {};
            const linha = document.createElement('tr');
            const colunaData = document.createElement('td');
            const colunaDia = document.createElement('td');
            colunaData.textContent = formatarDataBr(registro.data);
            colunaDia.textContent = nomeDiaSemana(registro.data);
            linha.appendChild(colunaData);
            linha.appendChild(colunaDia);

            const colunaSituacao = document.createElement('td');
            const inputs = {};
            preencherSituacaoPreview(colunaSituacao, registro.observacao, false);
            linha.appendChild(colunaSituacao);

            ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'].forEach(function (campo) {
                const coluna = document.createElement('td');
                const grupo = document.createElement('div');
                const input = document.createElement('input');
                const imagemRecorte = registroOriginal._imagens?.[campo] || '';

                grupo.className = 'ponto-preview-celula';

                if (imagemRecorte) {
                    const imagem = document.createElement('img');
                    imagem.src = imagemRecorte;
                    imagem.alt = 'Horário escrito na folha';
                    imagem.className = 'ponto-preview-recorte';
                    grupo.appendChild(imagem);
                    camposDetectadosPdf.add(indice + ':' + campo);

                }

                input.type = 'text';
                input.inputMode = 'numeric';
                input.autocomplete = 'off';
                input.maxLength = 5;
                input.placeholder = '--:--';
                input.className = 'form-control form-control-sm ponto-preview-hora';
                input.value = registro[campo];
                input.disabled = /^(?:feriado|folga|atestado)\b/i.test(registro.observacao);
                input.dataset.revisarOcr = registroOriginal._revisar?.[campo] ? '1' : '0';
                input.classList.toggle('ponto-preview-hora-sugerida', Boolean(imagemRecorte && registro[campo]));
                input.setAttribute('aria-label', campo + ' de ' + formatarDataBr(registro.data));
                inputs[campo] = input;
                input.addEventListener('input', function () {
                    input.classList.remove('ponto-preview-hora-sugerida');
                    input.dataset.revisarOcr = '0';
                    registrosPdfAtuais[indice][campo] = input.value;
                    validarRegistroPreview(indice, registrosPdfAtuais[indice], linha, colunaSituacao, inputs);
                    sincronizarRegistrosPdf();
                });
                input.addEventListener('blur', function () {
                    const horario = normalizarHorarioDigitado(input.value);
                    input.value = horario;
                    input.dataset.revisarOcr = '0';
                    registrosPdfAtuais[indice][campo] = horario;
                    validarRegistroPreview(indice, registrosPdfAtuais[indice], linha, colunaSituacao, inputs);
                    sincronizarRegistrosPdf();
                });
                input.addEventListener('keydown', function (evento) {
                    if (evento.key !== 'Enter') return;
                    evento.preventDefault();
                    input.blur();
                    const campos = Array.from(corpo.querySelectorAll('.ponto-preview-hora'));
                    const proximo = campos[campos.indexOf(input) + 1];
                    proximo?.focus();
                });
                input.dataset.preenchido = imagemRecorte ? '1' : '0';
                grupo.appendChild(input);
                coluna.appendChild(grupo);
                linha.appendChild(coluna);
            });
            corpo.appendChild(linha);
            validarRegistroPreview(indice, registro, linha, colunaSituacao, inputs);
        });

        sincronizarRegistrosPdf();
        avisoOcr?.classList.toggle('d-none', !leituraOcr);
        voltarTexto?.classList.toggle('d-none', registrosTextoReconhecido.length === 0);
        if (quantidade) quantidade.textContent = registrosPdfAtuais.length + (registrosPdfAtuais.length === 1 ? ' dia' : ' dias');
        preview.classList.remove('d-none');
    }

    function atualizarStatusImportacao(mensagem) {
        const texto = document.getElementById('statusImportacaoTexto');
        if (texto) texto.textContent = mensagem;
    }

    function recortarCelulaOcr(canvasPagina, area) {
        const contexto = canvasPagina.getContext('2d', { willReadFrequently: true });
        const x0 = Math.max(0, Math.floor(area.x0));
        const y0 = Math.max(0, Math.floor(area.y0));
        const largura = Math.max(1, Math.floor(area.x1 - area.x0));
        const altura = Math.max(1, Math.floor(area.y1 - area.y0));
        const imagem = contexto.getImageData(x0, y0, largura, altura);
        let ativos = [];
        const pixelsPorLinha = new Uint16Array(altura);
        const pixelsPorColuna = new Uint16Array(largura);
        let minimoX = largura;
        let minimoY = altura;
        let maximoX = 0;
        let maximoY = 0;

        for (let y = 0; y < altura; y += 1) {
            for (let x = 0; x < largura; x += 1) {
                const indice = ((y * largura) + x) * 4;
                const vermelho = imagem.data[indice];
                const verde = imagem.data[indice + 1];
                const azul = imagem.data[indice + 2];
                const luminancia = (vermelho * .299) + (verde * .587) + (azul * .114);
                const maiorCanal = Math.max(vermelho, verde, azul);
                const menorCanal = Math.min(vermelho, verde, azul);
                const saturacao = maiorCanal - menorCanal;
                const tintaEscura = luminancia < 135;
                const tintaColorida = saturacao > 35 && menorCanal < 210 && luminancia < 220;
                const ativo = tintaEscura || tintaColorida;

                if (!ativo) continue;
                ativos.push([x, y]);
                pixelsPorLinha[y] += 1;
                pixelsPorColuna[x] += 1;
            }
        }

        ativos = ativos.filter(function (ponto) {
            const linhaDaGrade = pixelsPorLinha[ponto[1]] > largura * .82;
            const colunaDaGrade = pixelsPorColuna[ponto[0]] > altura * .92;
            return !linhaDaGrade && !colunaDaGrade;
        });

        ativos.forEach(function (ponto) {
            minimoX = Math.min(minimoX, ponto[0]);
            minimoY = Math.min(minimoY, ponto[1]);
            maximoX = Math.max(maximoX, ponto[0]);
            maximoY = Math.max(maximoY, ponto[1]);
        });

        const minimoPixels = 30;
        if (ativos.length < minimoPixels) {
            return null;
        }

        const margem = 4;
        minimoX = Math.max(0, minimoX - margem);
        minimoY = Math.max(0, minimoY - margem);
        maximoX = Math.min(largura - 1, maximoX + margem);
        maximoY = Math.min(altura - 1, maximoY + margem);
        const recorteLargura = maximoX - minimoX + 1;
        const recorteAltura = maximoY - minimoY + 1;

        if (recorteAltura < Math.max(4, altura * .08) && recorteLargura > largura * .55) {
            return null;
        }

        const recorte = document.createElement('canvas');
        recorte.width = recorteLargura;
        recorte.height = recorteAltura;
        const contextoRecorte = recorte.getContext('2d');
        const binaria = contextoRecorte.createImageData(recorteLargura, recorteAltura);
        binaria.data.fill(255);

        ativos.forEach(function (ponto) {
            if (ponto[0] < minimoX || ponto[0] > maximoX || ponto[1] < minimoY || ponto[1] > maximoY) return;
            const destino = (((ponto[1] - minimoY) * recorteLargura) + (ponto[0] - minimoX)) * 4;
            binaria.data[destino] = 0;
            binaria.data[destino + 1] = 0;
            binaria.data[destino + 2] = 0;
            binaria.data[destino + 3] = 255;
        });
        contextoRecorte.putImageData(binaria, 0, 0);

        const escala = Math.max(3, Math.ceil(96 / recorteAltura));
        const ampliada = document.createElement('canvas');
        ampliada.width = (recorteLargura * escala) + 24;
        ampliada.height = (recorteAltura * escala) + 24;
        const contextoAmpliado = ampliada.getContext('2d');
        contextoAmpliado.fillStyle = '#fff';
        contextoAmpliado.fillRect(0, 0, ampliada.width, ampliada.height);
        contextoAmpliado.imageSmoothingEnabled = false;
        contextoAmpliado.drawImage(recorte, 12, 12, recorteLargura * escala, recorteAltura * escala);
        return ampliada;
    }

    async function canvasPaginaPdf(pdf, paginaNumero, escala) {
        const pagina = await pdf.getPage(paginaNumero);
        const viewport = pagina.getViewport({ scale: escala });
        const canvas = document.createElement('canvas');
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        await pagina.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
        return canvas;
    }

    function normalizarOrientacaoFolha(canvas) {
        if (canvas.width <= canvas.height) return canvas;

        const girado = document.createElement('canvas');
        girado.width = canvas.height;
        girado.height = canvas.width;
        girado.dataset.rotacionado = '1';
        const contexto = girado.getContext('2d');
        contexto.fillStyle = '#fff';
        contexto.fillRect(0, 0, girado.width, girado.height);
        contexto.translate(girado.width, 0);
        contexto.rotate(Math.PI / 2);
        contexto.drawImage(canvas, 0, 0);
        return girado;
    }

    function palavrasDoTsv(tsv) {
        return String(tsv || '').split(/[\r\n]+/).slice(1).map(function (linha) {
            const colunas = linha.split('\t');
            if (colunas.length < 12 || Number(colunas[0]) !== 5) return null;
            const texto = colunas.slice(11).join('\t').trim();
            if (!texto) return null;
            return {
                texto: texto,
                normalizado: textoNormalizado(texto).replace(/[^a-z0-9]/g, ''),
                x0: Number(colunas[6]),
                y0: Number(colunas[7]),
                x1: Number(colunas[6]) + Number(colunas[8]),
                y1: Number(colunas[7]) + Number(colunas[9]),
                confianca: Number(colunas[10])
            };
        }).filter(Boolean);
    }

    function centroPalavra(palavra) {
        return {
            x: (palavra.x0 + palavra.x1) / 2,
            y: (palavra.y0 + palavra.y1) / 2
        };
    }

    function ajustarReta(pontos) {
        if (pontos.length < 2) return null;
        const mediaX = pontos.reduce(function (total, ponto) { return total + ponto.x; }, 0) / pontos.length;
        const mediaY = pontos.reduce(function (total, ponto) { return total + ponto.y; }, 0) / pontos.length;
        let numerador = 0;
        let denominador = 0;

        pontos.forEach(function (ponto) {
            numerador += (ponto.x - mediaX) * (ponto.y - mediaY);
            denominador += Math.pow(ponto.x - mediaX, 2);
        });

        if (denominador === 0) return null;
        const inclinacao = numerador / denominador;
        return { inclinacao: inclinacao, intercepto: mediaY - (inclinacao * mediaX) };
    }

    function geometriaTabelaOcr(canvas, palavras, quantidadeDias) {
        if (!palavras?.length) return null;
        const rotulos = [
            ['entrada'],
            ['repouso', 'almoco'],
            ['retorno'],
            ['saida']
        ];
        const cabecalhos = rotulos.map(function (opcoes) {
            return palavras.filter(function (palavra) {
                const centro = centroPalavra(palavra);
                return opcoes.includes(palavra.normalizado)
                    && centro.y > canvas.height * .10
                    && centro.y < canvas.height * .48;
            }).sort(function (a, b) {
                return centroPalavra(a).y - centroPalavra(b).y;
            }).pop() || null;
        });

        if (cabecalhos.some(function (palavra) { return !palavra; })) return null;
        const centros = cabecalhos.map(centroPalavra);
        if (!centros.every(function (centro, indice) {
            return indice === 0 || centro.x > centros[indice - 1].x;
        })) return null;

        const limitesX = [
            centros[0].x - ((centros[1].x - centros[0].x) / 2),
            (centros[0].x + centros[1].x) / 2,
            (centros[1].x + centros[2].x) / 2,
            (centros[2].x + centros[3].x) / 2,
            centros[3].x + ((centros[3].x - centros[2].x) / 2)
        ];
        const topoCabecalho = Math.max.apply(null, cabecalhos.map(function (palavra) { return palavra.y1; }));
        const numerosDia = palavras.map(function (palavra) {
            const textoDia = palavra.texto.replace(/[oO]/g, '0').replace(/\D/g, '');
            const dia = /^\d{1,2}$/.test(textoDia) ? Number(textoDia) : 0;
            const centro = centroPalavra(palavra);
            if (
                dia < 1
                || dia > quantidadeDias
                || centro.x >= limitesX[0]
                || centro.y <= topoCabecalho
                || centro.y >= canvas.height * .90
            ) return null;
            return { dia: dia, x: centro.x, y: centro.y };
        }).filter(Boolean);
        const porDia = new Map();

        numerosDia.forEach(function (ponto) {
            if (!porDia.has(ponto.dia)) porDia.set(ponto.dia, ponto);
        });

        let pontosDias = Array.from(porDia.values()).map(function (ponto) {
            return { x: ponto.dia - 1, y: ponto.y, xOriginal: ponto.x };
        });
        let retaDias = ajustarReta(pontosDias);

        if (!retaDias || retaDias.inclinacao < canvas.height * .010 || retaDias.inclinacao > canvas.height * .030) {
            return null;
        }

        pontosDias = pontosDias.filter(function (ponto) {
            const previsto = retaDias.intercepto + (retaDias.inclinacao * ponto.x);
            return Math.abs(ponto.y - previsto) <= retaDias.inclinacao * .55;
        });
        if (pontosDias.length < Math.min(8, quantidadeDias)) return null;
        retaDias = ajustarReta(pontosDias);
        if (!retaDias) return null;

        const retaCabecalho = ajustarReta(centros.map(function (centro) {
            return { x: centro.x, y: centro.y };
        }));
        const inclinacaoHorizontal = retaCabecalho && Math.abs(retaCabecalho.inclinacao) <= .25
            ? retaCabecalho.inclinacao
            : 0;

        return {
            limitesX: limitesX,
            centroPrimeiroDia: retaDias.intercepto,
            alturaLinha: retaDias.inclinacao,
            xDias: pontosDias.reduce(function (total, ponto) { return total + ponto.xOriginal; }, 0) / pontosDias.length,
            inclinacaoHorizontal: inclinacaoHorizontal
        };
    }

    async function linhasDoPdfPorOcr(pdf) {
        if (!window.Tesseract?.createWorker) return { linhas: [], paginas: [] };

        atualizarStatusImportacao('O relatório é uma imagem. Iniciando a leitura do texto impresso...');
        const worker = await window.Tesseract.createWorker('por', 1, {
            logger: function (progresso) {
                if (progresso.status !== 'recognizing text') return;
                atualizarStatusImportacao('Lendo o relatório eletrônico... ' + Math.round((progresso.progress || 0) * 100) + '%');
            }
        });
        const linhas = [];
        const paginas = [];

        try {
            await worker.setParameters({ preserve_interword_spaces: '1' });

            for (let paginaNumero = 1; paginaNumero <= pdf.numPages; paginaNumero += 1) {
                const canvasOriginal = await canvasPaginaPdf(pdf, paginaNumero, 3.5);
                const canvas = normalizarOrientacaoFolha(canvasOriginal);
                const resultado = await worker.recognize(canvas, {}, { text: true, tsv: true });
                linhas.push.apply(linhas, String(resultado?.data?.text || '').split(/[\r\n]+/).map(function (linha) {
                    return linha.replace(/\s+/g, ' ').trim();
                }).filter(Boolean));
                paginas.push({ canvas: canvas, palavras: palavrasDoTsv(resultado?.data?.tsv) });
            }
        } finally {
            await worker.terminate();
        }

        return { linhas: linhas, paginas: paginas };
    }

    function horarioDoTextoManuscrito(texto) {
        const preparado = String(texto || '')
            .replace(/[oO]/g, '0')
            .replace(/[bB]/g, '8')
            .replace(/[iIlL|]/g, '1')
            .replace(/[sS]/g, '5')
            .replace(/\s+/g, '');
        const comSeparador = preparado.match(/([0-2]?\d)\s*[:hH.,]\s*([0-5]?\d)?/);

        if (comSeparador) {
            return normalizarHorarioDigitado(comSeparador[1] + ':' + (comSeparador[2] || '00'));
        }

        const digitos = preparado.replace(/\D/g, '');
        return digitos.length >= 1 && digitos.length <= 4 ? normalizarHorarioDigitado(digitos) : '';
    }

    async function reconhecerHorariosManuscritos(tarefas) {
        if (!window.Tesseract?.createWorker || tarefas.length === 0) return tarefas;

        const worker = await window.Tesseract.createWorker('por', 1);

        try {
            await worker.setParameters({
                tessedit_char_whitelist: '0123456789:hH.,',
                tessedit_pageseg_mode: '7',
                preserve_interword_spaces: '1'
            });

            for (let indice = 0; indice < tarefas.length; indice += 1) {
                atualizarStatusImportacao(
                    'Lendo marcações manuscritas... '
                    + (indice + 1) + ' de ' + tarefas.length
                );
                const resultado = await worker.recognize(tarefas[indice].canvas);
                tarefas[indice].horario = horarioDoTextoManuscrito(resultado?.data?.text || '');
                tarefas[indice].confianca = Number(resultado?.data?.confidence || 0);
            }
        } finally {
            await worker.terminate();
        }

        return tarefas;
    }

    function registrosOcrConfiaveis(registros) {
        const diasComPares = registros.filter(function (registro) {
            const horarios = [registro.entrada_1, registro.saida_1, registro.entrada_2, registro.saida_2].filter(Boolean);
            return horarios.length >= 2;
        });
        return diasComPares.length >= 2;
    }

    async function registrosDoPdfDigitalizado(pdf, mesSelecionado, textoOcr, paginasOcr) {
        atualizarStatusImportacao('Separando os horários escritos em cada dia...');
        const paginaOcr = paginasOcr?.[0] || null;
        const canvasOriginal = paginaOcr?.canvas || await canvasPaginaPdf(pdf, 1, 2.4);
        const canvas = paginaOcr?.canvas || normalizarOrientacaoFolha(canvasOriginal);
        const palavras = paginaOcr?.palavras || [];
        const textoModelo = textoNormalizado(textoOcr);
        const modeloListaFrequencia = /lista de frequencia/.test(textoModelo)
            || (/dia\s*(?:do\s*)?mes/.test(textoModelo) && /repouso/.test(textoModelo));
        const partesMes = mesSelecionado.split('-').map(Number);
        const quantidadeDias = new Date(partesMes[0], partesMes[1], 0).getDate();
        const geometriaOcr = geometriaTabelaOcr(canvas, palavras, quantidadeDias);
        const perfilFotoRotacionada = canvas.dataset.rotacionado === '1';
        const limitesXProporcao = perfilFotoRotacionada
            ? [.192, .260, .329, .399, .471]
            : [.168, .258, .351, .445, .535];
        const limitesX = geometriaOcr?.limitesX || limitesXProporcao.map(function (valor) {
            return canvas.width * valor;
        });
        const topoLinhas = canvas.height * (perfilFotoRotacionada ? .225 : (modeloListaFrequencia ? .224 : .204));
        const baseLinhas = canvas.height * (perfilFotoRotacionada ? .763 : (modeloListaFrequencia ? .852 : .840));
        const alturaLinha = geometriaOcr?.alturaLinha || ((baseLinhas - topoLinhas) / 31);
        const inclinacaoHorizontal = geometriaOcr?.inclinacaoHorizontal || (perfilFotoRotacionada ? -.11 : 0);
        const xReferenciaLinhas = geometriaOcr?.xDias || (perfilFotoRotacionada ? canvas.width * .226 : 0);
        const campos = ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'];
        const situacoes = new Map();
        let tarefas = [];

        palavras.forEach(function (palavra) {
            let observacao = '';
            if (palavra.normalizado.includes('atestado')) observacao = 'Atestado';
            else if (palavra.normalizado.includes('feriado')) observacao = 'Feriado';
            else if (palavra.normalizado.includes('folga')) observacao = 'Folga';
            if (!observacao) return;

            const centro = centroPalavra(palavra);
            const centroPrimeiro = geometriaOcr?.centroPrimeiroDia || (topoLinhas + (alturaLinha / 2));
            const yCorrigido = centro.y - (inclinacaoHorizontal * (centro.x - xReferenciaLinhas));
            const dia = Math.round((yCorrigido - centroPrimeiro) / alturaLinha) + 1;
            if (dia >= 1 && dia <= quantidadeDias) situacoes.set(dia, observacao);
        });

        for (let dia = 1; dia <= quantidadeDias; dia += 1) {
            for (let coluna = 0; coluna < campos.length; coluna += 1) {
                const larguraCelula = limitesX[coluna + 1] - limitesX[coluna];
                const margemX = Math.max(8, larguraCelula * .055);
                const margemY = Math.max(4, alturaLinha * .10);
                const centroX = (limitesX[coluna] + limitesX[coluna + 1]) / 2;
                const centroY = geometriaOcr
                    ? geometriaOcr.centroPrimeiroDia
                    + ((dia - 1) * alturaLinha)
                    + (inclinacaoHorizontal * (centroX - xReferenciaLinhas))
                    : topoLinhas
                    + ((dia - .5) * alturaLinha)
                    + (inclinacaoHorizontal * (centroX - xReferenciaLinhas));
                const area = {
                    x0: limitesX[coluna] + margemX,
                    x1: limitesX[coluna + 1] - margemX,
                    y0: centroY - (alturaLinha / 2) + margemY,
                    y1: centroY + (alturaLinha / 2) - margemY
                };
                const recorte = recortarCelulaOcr(canvas, area);
                if (recorte) tarefas.push({ dia: dia, campo: campos[coluna], canvas: recorte });
            }
        }

        if (tarefas.length === 0 && situacoes.size === 0) {
            throw new Error('Não encontrei marcações manuscritas neste modelo e o texto impresso também não formou registros válidos.');
        }

        if (tarefas.length > 0) tarefas = await reconhecerHorariosManuscritos(tarefas);

        const porDia = new Map();

        situacoes.forEach(function (observacao, dia) {
            porDia.set(dia, {
                data: mesSelecionado + '-' + String(dia).padStart(2, '0'),
                entrada_1: '',
                saida_1: '',
                entrada_2: '',
                saida_2: '',
                observacao: observacao,
                _imagens: {},
                _revisar: {}
            });
        });

        tarefas.forEach(function (tarefa) {
            if (situacoes.has(tarefa.dia)) return;
            if (!porDia.has(tarefa.dia)) {
                porDia.set(tarefa.dia, {
                    data: mesSelecionado + '-' + String(tarefa.dia).padStart(2, '0'),
                    entrada_1: '',
                    saida_1: '',
                    entrada_2: '',
                    saida_2: '',
                    _imagens: {},
                    _revisar: {}
                });
            }
            const registro = porDia.get(tarefa.dia);
            registro._imagens[tarefa.campo] = tarefa.canvas.toDataURL('image/png');
            const horarioConfiavel = tarefa.horario && tarefa.confianca >= 45;
            registro[tarefa.campo] = horarioConfiavel ? tarefa.horario : '';
            registro._revisar[tarefa.campo] = !horarioConfiavel;
        });

        return Array.from(porDia.values()).sort(function (a, b) {
            return a.data.localeCompare(b.data);
        });
    }

    async function lerPdf() {
        limparImportacaoPdf();
        const arquivo = document.getElementById('arquivoPontoPdf');
        const status = document.getElementById('statusImportacaoPdf');
        let mes = document.querySelector('#formImportarPdf input[name="mes"]')?.value || '';

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

        mes = definirMesImportacao(mesDoTextoPdf(arquivo.files[0].name) || mes);

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

            const mesDoTexto = mesDoTextoPdf(linhas.join(' '));
            if (mesDoTexto) mes = definirMesImportacao(mesDoTexto);

            let registros = totalItens > 0 ? registrosDasLinhas(linhas, mes) : [];
            let leituraOcr = false;
            let textoOcr = '';
            let paginasOcr = [];

            if (registros.length === 0) {
                try {
                    const dadosOcr = await linhasDoPdfPorOcr(pdf);
                    const linhasOcr = dadosOcr.linhas;
                    paginasOcr = dadosOcr.paginas;
                    textoOcr = linhasOcr.join(' ');
                    const mesOcr = mesDoTextoPdf(textoOcr);
                    if (mesOcr) mes = definirMesImportacao(mesOcr);
                    const registrosOcr = registrosDasLinhas(linhasOcr, mes);
                    if (registrosOcrConfiaveis(registrosOcr)) registros = registrosOcr;
                } catch (erroOcr) {
                    registros = [];
                }
            }

            if (registros.length === 0) {
                leituraOcr = true;
                registros = await registrosDoPdfDigitalizado(pdf, mes, textoOcr, paginasOcr);
            }

            if (registros.length === 0) {
                throw new Error('A imagem foi lida, mas nenhum horário pôde ser reconhecido. Tente uma digitalização mais nítida.');
            }

            if (leituraOcr) mostrarTextoReconhecido(registros);
            else mostrarPreviewPdf(registros, false);
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

        const modalExcluirFuncionario = document.getElementById('modalExcluirFuncionario');
        modalExcluirFuncionario?.addEventListener('show.bs.modal', function (evento) {
            const botao = evento.relatedTarget;

            if (!botao?.dataset.id) {
                return;
            }

            const excluirId = document.getElementById('funcionarioExcluirId');
            const excluirNome = document.getElementById('funcionarioExcluirNome');

            if (excluirId) excluirId.value = botao.dataset.id;
            if (excluirNome) excluirNome.textContent = botao.dataset.nome || 'este funcionário';
        });

        document.getElementById('btnExcluirFuncionario')?.addEventListener('click', function () {
            const id = document.getElementById('funcionarioIdModal')?.value || '';
            const nome = document.getElementById('funcionarioNome')?.value || 'este funcionário';
            const excluirId = document.getElementById('funcionarioExcluirId');
            const excluirNome = document.getElementById('funcionarioExcluirNome');
            const modalEdicao = bootstrap.Modal.getInstance(document.getElementById('modalFuncionario'));
            const modalExclusaoElemento = modalExcluirFuncionario;

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
            campo.addEventListener('input', atualizarResumoRegistros);
            campo.addEventListener('change', atualizarResumoRegistros);
        });
        document.querySelectorAll('.ponto-observacao').forEach(function (campo) {
            campo.addEventListener('input', atualizarResumoRegistros);
            campo.addEventListener('change', atualizarResumoRegistros);
        });
        document.querySelectorAll('.ponto-atestado-check').forEach(function (campo) {
            campo.addEventListener('change', function () {
                const linha = campo.closest('.ponto-registro-linha');

                linha?.querySelectorAll('.ponto-hora-registro').forEach(function (horario) {
                    if (campo.checked) horario.value = '';
                    horario.disabled = campo.checked;
                });

                atualizarResumoRegistros();
            });
        });
        atualizarResumoRegistros();

        const modalPdf = document.getElementById('modalImportarPdf');
        modalPdf?.addEventListener('hidden.bs.modal', function () {
            document.getElementById('formImportarPdf')?.reset();
            limparImportacaoPdf();
        });

        document.getElementById('arquivoPontoPdf')?.addEventListener('change', limparImportacaoPdf);
        document.getElementById('btnLerPdf')?.addEventListener('click', lerPdf);
        document.getElementById('btnAplicarTextoPdf')?.addEventListener('click', aplicarTextoReconhecido);
        document.getElementById('textoReconhecidoPdf')?.addEventListener('input', function (evento) {
            evento.currentTarget.classList.remove('is-invalid');
            document.getElementById('erroImportacaoPdf')?.classList.add('d-none');
        });
        document.getElementById('btnVoltarTextoPdf')?.addEventListener('click', function () {
            document.getElementById('previewImportacaoPdf')?.classList.add('d-none');
            document.getElementById('etapaTextoImportacaoPdf')?.classList.remove('d-none');
            const oculto = document.getElementById('registrosPdf');
            const confirmar = document.getElementById('btnConfirmarImportacaoPdf');
            if (oculto) oculto.value = '';
            if (confirmar) confirmar.disabled = true;
        });
        document.getElementById('formImportarPdf')?.addEventListener('submit', function (evento) {
            if (!document.getElementById('registrosPdf')?.value) {
                evento.preventDefault();
                mostrarErroPdf('Leia e confira o PDF antes de confirmar a importação.');
            }
        });
    });
}());