/**
 * Plugin Regular Atendimento - relógio ao vivo no chamado, iniciar/pausar, ajuste, impressão e PDF do extrato
 */
(function () {
    'use strict';

    if (window.RegularAtendimento) {
        return;
    }
    window.RegularAtendimento = true;

    var raiz = (window.CFG_GLPI && window.CFG_GLPI.root_doc) ? window.CFG_GLPI.root_doc : '';
    var urlAjax = raiz + '/plugins/regularatendimento/front/ajax.php';
    var LIB_CANVAS = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
    var LIB_PDF = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';

    function aviso(texto, erro) {
        if (erro && typeof window.glpi_toast_error === 'function') {
            window.glpi_toast_error(texto);
        } else if (!erro && typeof window.glpi_toast_info === 'function') {
            window.glpi_toast_info(texto);
        }
    }

    function lerJson(texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = texto.match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try { return JSON.parse(m[0]); } catch (e2) { /* segue */ }
            }
        }
        return { success: false, message: 'Resposta inválida do servidor.' };
    }

    function pedir(acao, dados, metodo) {
        var opcoes = { method: metodo || 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        var url = urlAjax + '?action=' + encodeURIComponent(acao);
        if (opcoes.method === 'GET') {
            Object.keys(dados).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(dados[k]); });
        } else {
            var fd = new FormData();
            Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
            var m = document.querySelector('meta[property="glpi:csrf_token"]');
            var t = m ? m.getAttribute('content') : '';
            if (t) {
                opcoes.headers['X-Glpi-Csrf-Token'] = t;
                fd.append('_glpi_csrf_token', t);
            }
            opcoes.body = fd;
        }
        return fetch(url, opcoes).then(function (r) { return r.text(); }).then(function (txt) {
            var j = lerJson(txt);
            var m = document.querySelector('meta[property="glpi:csrf_token"]');
            if (j.new_token && m) { m.setAttribute('content', j.new_token); }
            return j;
        });
    }

    // ------------------------------------------------------------------ entidades: esconde as filhas
    if (window.jQuery) {
        window.jQuery.ajaxPrefilter(function (opcoes) {
            var ocultas = window.regularEntidadesOcultas || [];
            if (!ocultas.length || !opcoes.url || opcoes.url.indexOf('getDropdownValue') === -1 || (typeof opcoes.data === 'string' ? opcoes.data : '').indexOf('itemtype=Entity') === -1) {
                return;
            }
            var original = opcoes.success;
            opcoes.success = function (resposta) {
                var r = resposta;
                try {
                    if (typeof r === 'string') { r = JSON.parse(r); }
                    var filtrar = function (lista) {
                        return lista.filter(function (it) {
                            if (it.children) {
                                it.children = filtrar(it.children);
                                return it.children.length > 0;
                            }
                            return ocultas.indexOf(parseInt(it.id, 10)) === -1;
                        });
                    };
                    if (r && r.results) { r.results = filtrar(r.results); }
                } catch (e) { r = resposta; }
                if (typeof original === 'function') {
                    return original.apply(this, [r].concat([].slice.call(arguments, 1)));
                }
                return r;
            };
        });
    }

    // ------------------------------------------------------------------ relógio ao vivo
    function formatar(s) {
        s = Math.max(0, Math.floor(s));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var x = s % 60;
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (x < 10 ? '0' : '') + x;
    }

    function tique() {
        var agora = Date.now();
        document.querySelectorAll('[data-regular-segundos]').forEach(function (el) {
            if (!el.hasAttribute('data-t0')) {
                el.setAttribute('data-t0', String(agora));
            }
            if (el.getAttribute('data-regular-rodando') !== '1') {
                return;
            }
            var base = parseInt(el.getAttribute('data-regular-segundos'), 10) || 0;
            var t0 = parseInt(el.getAttribute('data-t0'), 10) || agora;
            el.textContent = formatar(base + (agora - t0) / 1000);
        });
    }
    setInterval(tique, 1000);

    function trocarCartao(cartao, html) {
        if (!html) { return; }
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var novo = tmp.firstElementChild;
        if (novo) {
            cartao.replaceWith(novo);
            // Os demais cartões do mesmo chamado (formulário e aba) também são atualizados
            document.querySelectorAll('[data-regular-cartao="' + novo.getAttribute('data-regular-cartao') + '"]').forEach(function (c) {
                if (c !== novo) { c.replaceWith(novo.cloneNode(true)); }
            });
            tique();
        }
    }

    // Mantém o cartão em dia quando o status muda por outra pessoa (a cada minuto, com a aba visível)
    setInterval(function () {
        if (document.visibilityState !== 'visible') { return; }
        var c = document.querySelector('[data-regular-cartao]');
        if (!c || c.querySelector('.regular-pausa:not([hidden])')) { return; }
        pedir('estado', { ticket: c.getAttribute('data-regular-cartao') }, 'GET').then(function (j) {
            if (j.success && j.dados && (j.dados.estado !== c.getAttribute('data-estado') || j.dados.estado === 'rodando')) {
                trocarCartao(c, j.html);
            }
        }).catch(function () { /* tenta no próximo minuto */ });
    }, 60000);

    // ------------------------------------------------------------------ eventos
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-regular-acao]');
        if (b) {
            var cartao = b.closest('[data-regular-cartao]');
            var acao = b.getAttribute('data-regular-acao');
            var pausa = cartao.querySelector('.regular-pausa');
            if (acao === 'pausar') {
                pausa.hidden = false;
                b.hidden = true;
                pausa.querySelector('input').focus();
                return;
            }
            if (acao === 'pausar_cancelar') {
                pausa.hidden = true;
                cartao.querySelector('[data-regular-acao="pausar"]').hidden = false;
                return;
            }
            var dados = { ticket: cartao.getAttribute('data-regular-cartao') };
            if (acao === 'pausar_confirmar') {
                acao = 'pausar';
                dados.motivo = pausa.querySelector('input').value;
            }
            b.disabled = true;
            pedir(acao, dados, 'POST').then(function (j) {
                aviso(j.message || '', !j.success);
                trocarCartao(cartao, j.html);
            }).catch(function () {
                b.disabled = false;
                aviso('Falha de comunicação com o servidor.', true);
            });
            return;
        }
        var conf = ev.target.closest('[data-regular-confirmar]');
        if (conf && !conf.classList.contains('regular-confirmando')) {
            ev.preventDefault();
            conf.classList.add('regular-confirmando');
            conf.setAttribute('data-html', conf.innerHTML);
            conf.innerHTML = '<i class="ti ti-alert-triangle"></i> Confirmar';
            setTimeout(function () {
                if (conf.isConnected) {
                    conf.classList.remove('regular-confirmando');
                    conf.innerHTML = conf.getAttribute('data-html');
                }
            }, 4000);
            return;
        }
        if (ev.target.closest('[data-regular-imprimir]')) {
            imprimir();
            return;
        }
        var bPdf = ev.target.closest('[data-regular-pdf]');
        if (bPdf) {
            bPdf.disabled = true;
            gerarPdf(document.querySelector('[data-regular-documento]')).then(function (arq) {
                arq.save((document.querySelector('[data-regular-relatorio]') || {}).getAttribute ? document.querySelector('[data-regular-relatorio]').getAttribute('data-arquivo') : 'extrato.pdf');
            }).catch(function (e) { aviso(e.message, true); }).finally(function () { bPdf.disabled = false; });
        }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && ev.target.matches('[data-regular-motivo]')) {
            ev.preventDefault();
            ev.target.closest('.regular-pausa').querySelector('[data-regular-acao="pausar_confirmar"]').click();
        }
    });

    document.addEventListener('submit', function (ev) {
        var f = ev.target.closest('[data-regular-ajuste]');
        if (!f) { return; }
        ev.preventDefault();
        var botao = f.querySelector('[type="submit"]');
        botao.disabled = true;
        pedir('ajustar', { ticket: f.getAttribute('data-regular-ajuste'), minutos: f.querySelector('[name="minutos"]').value, motivo: f.querySelector('[name="motivo"]').value }, 'POST').then(function (j) {
            if (j.success) {
                window.location.reload();
            } else {
                botao.disabled = false;
                aviso(j.message, true);
            }
        });
    });

    document.addEventListener('focusout', function (ev) {
        var el = ev.target;
        if (!el.matches || !el.matches('[data-regular-moeda]')) { return; }
        var t = (el.value || '').trim();
        if (t === '') { return; }
        if (t.indexOf(',') !== -1) { t = t.replace(/\./g, '').replace(',', '.'); }
        var n = parseFloat(t.replace(/[^0-9.]/g, ''));
        el.value = isNaN(n) ? '' : n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    });

    // ------------------------------------------------------------------ impressão e PDF do extrato
    var carregados = {};
    function carregarScript(src) {
        if (!carregados[src]) {
            carregados[src] = new Promise(function (ok, falha) {
                var s = document.createElement('script');
                s.src = src;
                s.onload = ok;
                s.onerror = function () { falha(new Error('Não foi possível carregar ' + src)); };
                document.head.appendChild(s);
            });
        }
        return carregados[src];
    }

    function gerarPdf(doc) {
        return Promise.all([carregarScript(LIB_CANVAS), carregarScript(LIB_PDF)]).then(function () {
            var escala = 2;
            var topo = doc.getBoundingClientRect().top;
            var pontos = [];
            doc.querySelectorAll('tr, div').forEach(function (el) {
                var r = el.getBoundingClientRect();
                if (r.height > 0) { pontos.push(Math.round((r.bottom - topo) * escala)); }
            });
            pontos.sort(function (a, b) { return a - b; });
            return window.html2canvas(doc, { scale: escala, useCORS: true, backgroundColor: '#ffffff' }).then(function (canvas) {
                var arquivo = new window.jspdf.jsPDF('p', 'mm', 'a4');
                var margem = 10;
                var largura = 190;
                var pxPorMm = canvas.width / largura;
                var fatia = Math.floor((297 - 2 * margem - 4) * pxPorMm);
                var y = 0;
                var n = 0;
                while (y < canvas.height - 2) {
                    var fim = Math.min(y + fatia, canvas.height);
                    if (fim < canvas.height) {
                        var melhor = 0;
                        pontos.forEach(function (p) { if (p > y + fatia * 0.6 && p <= y + fatia) { melhor = p; } });
                        if (melhor > 0) { fim = melhor; }
                    }
                    var parte = document.createElement('canvas');
                    parte.width = canvas.width;
                    parte.height = fim - y;
                    var c2 = parte.getContext('2d');
                    c2.fillStyle = '#ffffff';
                    c2.fillRect(0, 0, parte.width, parte.height);
                    c2.drawImage(canvas, 0, y, canvas.width, parte.height, 0, 0, canvas.width, parte.height);
                    if (n > 0) { arquivo.addPage(); }
                    arquivo.addImage(parte.toDataURL('image/jpeg', 0.9), 'JPEG', margem, margem, largura, parte.height / pxPorMm);
                    y = fim;
                    n++;
                }
                var total = arquivo.getNumberOfPages();
                for (var i = 1; i <= total; i++) {
                    arquivo.setPage(i);
                    arquivo.setFontSize(8);
                    arquivo.setTextColor(150);
                    arquivo.text(i + ' / ' + total, 200, 292, { align: 'right' });
                }
                return arquivo;
            });
        });
    }

    function imprimir() {
        var doc = document.querySelector('[data-regular-documento]');
        var janela = window.open('', '_blank');
        if (!doc || !janela) {
            aviso('O navegador bloqueou a janela de impressão.', true);
            return;
        }
        janela.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + document.title + '</title><style>@page{size:A4;margin:10mm}body{margin:0}</style></head><body>' + doc.outerHTML + '</body></html>');
        janela.document.close();
        janela.onload = function () { janela.focus(); janela.print(); };
    }
})();
