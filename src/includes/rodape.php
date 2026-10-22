</main>

<footer class="container pb-4 text-muted small">
    <a href="/privacidade.php" class="text-muted">Privacidade e proteção de dados</a>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/app.js" defer></script>
<script>
  // Aciona o cron silenciosamente assim que qualquer página carregar
  window.addEventListener('DOMContentLoaded', () => {
    fetch('cron_avisos.php?key=Kosmos2026@')
      .then(res => res.text())
      .then(data => console.log('Fila de e-mails processada:', data))
      .catch(err => console.error('Erro ao acionar cron:', err));
  });
</script>
</body>
</html>
