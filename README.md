# Regular Atendimento para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

Controla o **tempo de atendimento** de cada chamado e as **horas contratadas** por cliente. Um relógio no chamado roda conforme o status e cobra o tempo no contrato da entidade, com franquia, hora normal e hora extra. Ele une os antigos plugins "relogio" e "relogiodeatendimento".

## O que o plugin faz

### Relógio no chamado
- **Roda sozinho conforme o status:**
  - **inicia** quando o chamado entra em atendimento (opcional);
  - **pausa** quando fica pendente;
  - **finaliza e cobra** na solução;
  - se o chamado for **reaberto**, a cobrança é **estornada** e o relógio continua.
- **Iniciar, pausar** (com motivo) e **retomar** também à mão.
- **Ajuste** manual do tempo, com registro.
- O relógio aparece **ao vivo** no formulário do chamado, e a aba **Tempo de atendimento** mostra cada trecho trabalhado.

### Contratos de horas por entidade
- **Franquia** em minutos, **valor da hora normal** e **da hora extra**, **tempo mínimo** cobrado e **arredondamento**.
- **Acumular saldo** de um ciclo para o outro.
- Incluir as **entidades filhas**.
- **Calendário** do contrato: o tempo fora do calendário é sempre cobrado como hora extra.
- **Dia de fechamento** do ciclo.
- O contrato é um **item nativo**, com lista, formulário e histórico.

### Ciclos e extrato
- **Ciclos mensais** abertos e fechados automaticamente no dia de fechamento, pela ação automática de hora em hora.
- **Extrato** com débitos (normal e extra), estornos, créditos, ajustes, abertura e fechamento, cada um com o saldo antes e depois.
- **Lançamento manual:** crédito de horas ou consumo avulso.
- **Relatório A4 do ciclo**, para imprimir ou baixar em PDF, e **exportação CSV**.

### Lançar atendimento já feito
Registra um atendimento passado: cria o chamado já solucionado e cobra o tempo informado.

### Painel
Contratos no ciclo atual, com o consumo, e os **relógios rodando agora**.

## Configuração e direitos

- **Direito nativo** na aba do perfil.
- Opções da página de configuração:
  - iniciar o relógio automaticamente;
  - mostrar o relógio em chamados sem contrato;
  - mostrar o relógio ao solicitante;
  - **valores padrão** para contratos novos: hora normal, hora extra, mínimo, arredondamento e franquia.

O menu fica em **Ferramentas → Regular Atendimento**.

---

## Download e instalação

1. Baixe o arquivo `regularatendimento-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/regularatendimento/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/regularatendimento
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install regularatendimento -u <usuário administrador>
   php bin/console plugin:activate regularatendimento
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/regularatendimento` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install regularatendimento -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).