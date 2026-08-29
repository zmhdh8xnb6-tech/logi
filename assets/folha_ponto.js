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
    let camposDetectadosPdf = new Set();

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
        document.getElementById('avisoRevisaoOcr')?.classList.add('d-none');
        const oculto = document.getElementById('registrosPdf');
        const confirmar = document.getElementById('btnConfirmarImportacaoPdf');
        const corpo = document.getElementById('corpoImportacaoPdf');
        const textoStatus = document.getElementById('statusImportacaoTexto');
        registrosPdfAtuais = [];
        camposDetectadosPdf = new Set();
        if (oculto) oculto.value = '';
        if (confirmar) confirmar.disabled = true;
        if (corpo) corpo.replaceChildren();
        if (textoStatus) textoStatus.textContent = 'Lendo o arquivo...';
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

    function horariosFuncionarioSelecionado() {
        const botao = document.getElementById('btnEditarFuncionario');

        try {
            const horarios = JSON.parse(botao?.dataset.horarios || '[]');
            return Array.isArray(horarios) ? horarios : [];
        } catch (erro) {
            return [];
        }
    }

    function horarioPrevistoPdf(dataIso, campo) {
        const partes = dataIso.split('-').map(Number);
        const diaJs = new Date(partes[0], partes[1] - 1, partes[2], 12).getDay();
        const diaSemana = diaJs === 0 ? 7 : diaJs;
        const horarioDia = horariosFuncionarioSelecionado().find(function (horario) {
            return Number(horario.dia_semana) === diaSemana;
        });

        if (!horarioDia || Number(horarioDia.trabalha) !== 1) return '';
        return String(horarioDia[campo] || '').slice(0, 5);
    }

    function sincronizarRegistrosPdf() {
        const oculto = document.getElementById('registrosPdf');
        const confirmar = document.getElementById('btnConfirmarImportacaoPdf');
        const algumHorario = registrosPdfAtuais.some(function (registro) {
            return ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'].some(function (campo) {
                return registro[campo] !== '';
            });
        });
        const horariosValidos = registrosPdfAtuais.every(function (registro) {
            return ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'].every(function (campo) {
                return registro[campo] === '' || /^([01]\d|2[0-3]):[0-5]\d$/.test(registro[campo]);
            });
        });
        const detectadosPreenchidos = Array.from(camposDetectadosPdf).every(function (chave) {
            const partes = chave.split(':');
            return Boolean(registrosPdfAtuais[Number(partes[0])]?.[partes[1]]);
        });

        if (oculto) oculto.value = horariosValidos && algumHorario && detectadosPreenchidos ? JSON.stringify(registrosPdfAtuais) : '';
        if (confirmar) confirmar.disabled = registrosPdfAtuais.length === 0 || !horariosValidos || !algumHorario || !detectadosPreenchidos;
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

    function mostrarPreviewPdf(registros, leituraOcr) {
        const corpo = document.getElementById('corpoImportacaoPdf');
        const preview = document.getElementById('previewImportacaoPdf');
        const quantidade = document.getElementById('quantidadeImportacaoPdf');
        const avisoOcr = document.getElementById('avisoRevisaoOcr');

        if (!corpo || !preview) return;
        corpo.replaceChildren();
        camposDetectadosPdf = new Set();
        registrosPdfAtuais = registros.map(function (registro) {
            return {
                data: registro.data,
                entrada_1: registro.entrada_1 || '',
                saida_1: registro.saida_1 || '',
                entrada_2: registro.entrada_2 || '',
                saida_2: registro.saida_2 || ''
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

                    if (!registro[campo]) {
                        registro[campo] = horarioPrevistoPdf(registro.data, campo);
                    }
                }

                input.type = 'text';
                input.inputMode = 'numeric';
                input.autocomplete = 'off';
                input.maxLength = 5;
                input.placeholder = '--:--';
                input.className = 'form-control form-control-sm ponto-preview-hora';
                input.value = registro[campo];
                input.classList.toggle('ponto-preview-hora-sugerida', Boolean(imagemRecorte && registro[campo]));
                input.setAttribute('aria-label', campo + ' de ' + formatarDataBr(registro.data));
                input.addEventListener('input', function () {
                    input.classList.remove('ponto-preview-hora-sugerida');
                    registrosPdfAtuais[indice][campo] = input.value;
                    sincronizarRegistrosPdf();
                });
                input.addEventListener('blur', function () {
                    const horario = normalizarHorarioDigitado(input.value);
                    input.value = horario;
                    input.classList.toggle('is-invalid', input.value === '' && input.dataset.preenchido === '1');
                    registrosPdfAtuais[indice][campo] = horario;
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
        });

        sincronizarRegistrosPdf();
        avisoOcr?.classList.toggle('d-none', !leituraOcr);
        if (quantidade) quantidade.textContent = registrosPdfAtuais.length + (registrosPdfAtuais.length === 1 ? ' dia' : ' dias');
        preview.classList.remove('d-none');
    }

    function atualizarStatusImportacao(mensagem) {
        const texto = document.getElementById('statusImportacaoTexto');
        if (texto) texto.textContent = mensagem;
    }

    function recortarCelulaOcr(canvasPagina, area, somenteAzul) {
        const contexto = canvasPagina.getContext('2d', { willReadFrequently: true });
        const x0 = Math.max(0, Math.floor(area.x0));
        const y0 = Math.max(0, Math.floor(area.y0));
        const largura = Math.max(1, Math.floor(area.x1 - area.x0));
        const altura = Math.max(1, Math.floor(area.y1 - area.y0));
        const imagem = contexto.getImageData(x0, y0, largura, altura);
        const ativos = [];
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
                const ativo = somenteAzul
                    ? azul - vermelho > 25 && azul > verde * 1.04 && azul > vermelho * 1.12
                    : luminancia < 115;

                if (!ativo) continue;
                ativos.push([x, y]);
                minimoX = Math.min(minimoX, x);
                minimoY = Math.min(minimoY, y);
                maximoX = Math.max(maximoX, x);
                maximoY = Math.max(maximoY, y);
            }
        }

        const minimoPixels = somenteAzul ? 18 : 45;
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

    async function registrosDoPdfDigitalizado(pdf, mesSelecionado) {
        atualizarStatusImportacao('Separando os horários escritos em cada dia...');
        const pagina = await pdf.getPage(1);
        const viewport = pagina.getViewport({ scale: 2.4 });
        const canvas = document.createElement('canvas');
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        await pagina.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;

        const limitesX = [.168, .258, .351, .445, .535];
        const topoLinhas = canvas.height * .204;
        const baseLinhas = canvas.height * .840;
        const alturaLinha = (baseLinhas - topoLinhas) / 31;
        const partesMes = mesSelecionado.split('-').map(Number);
        const quantidadeDias = new Date(partesMes[0], partesMes[1], 0).getDate();
        const campos = ['entrada_1', 'saida_1', 'entrada_2', 'saida_2'];
        let tarefas = [];

        for (let dia = 1; dia <= quantidadeDias; dia += 1) {
            for (let coluna = 0; coluna < campos.length; coluna += 1) {
                const area = {
                    x0: (canvas.width * limitesX[coluna]) + 6,
                    x1: (canvas.width * limitesX[coluna + 1]) - 6,
                    y0: topoLinhas + ((dia - 1) * alturaLinha) + 4,
                    y1: topoLinhas + (dia * alturaLinha) - 4
                };
                const recorte = recortarCelulaOcr(canvas, area, true);
                if (recorte) tarefas.push({ dia: dia, campo: campos[coluna], canvas: recorte });
            }
        }

        if (tarefas.length === 0) {
            for (let dia = 1; dia <= quantidadeDias; dia += 1) {
                for (let coluna = 0; coluna < campos.length; coluna += 1) {
                    const area = {
                        x0: (canvas.width * limitesX[coluna]) + 6,
                        x1: (canvas.width * limitesX[coluna + 1]) - 6,
                        y0: topoLinhas + ((dia - 1) * alturaLinha) + 4,
                        y1: topoLinhas + (dia * alturaLinha) - 4
                    };
                    const recorte = recortarCelulaOcr(canvas, area, false);
                    if (recorte) tarefas.push({ dia: dia, campo: campos[coluna], canvas: recorte });
                }
            }
        }

        if (tarefas.length === 0) {
            throw new Error('Não encontrei horários escritos nas colunas da folha. Confira o modelo e a qualidade da digitalização.');
        }

        const porDia = new Map();

        tarefas.forEach(function (tarefa) {
            if (!porDia.has(tarefa.dia)) {
                porDia.set(tarefa.dia, {
                    data: mesSelecionado + '-' + String(tarefa.dia).padStart(2, '0'),
                    entrada_1: '',
                    saida_1: '',
                    entrada_2: '',
                    saida_2: '',
                    _imagens: {}
                });
            }
            porDia.get(tarefa.dia)._imagens[tarefa.campo] = tarefa.canvas.toDataURL('image/png');
        });

        return Array.from(porDia.values()).sort(function (a, b) {
            return a.data.localeCompare(b.data);
        });
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

            let registros = totalItens > 0 ? registrosDasLinhas(linhas, mes) : [];
            let leituraOcr = false;

            if (registros.length === 0) {
                leituraOcr = true;
                registros = await registrosDoPdfDigitalizado(pdf, mes);
            }

            if (registros.length === 0) {
                throw new Error('A imagem foi lida, mas nenhum horário pôde ser reconhecido. Tente uma digitalização mais nítida.');
            }

            mostrarPreviewPdf(registros, leituraOcr);
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