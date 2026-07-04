<div class="card" style="margin-top:20px">
    <h3>🖼️ Распознать ИНН, телефон и адрес с изображения</h3>
    <form id="ocrForm" enctype="multipart/form-data">
        <input type="file" name="image" accept="image/*" required style="margin-right:10px">
        <button type="submit" class="ask-btn">🔍 Распознать</button>
    </form>
    <div id="ocrResult" class="ai-result" style="display:none; margin-top:10px;"></div>
</div>

<script>
document.getElementById('ocrForm').addEventListener('submit', function(e) {
    e.preventDefault();
    let formData = new FormData(this);
    let resultDiv = document.getElementById('ocrResult');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '⏳ Обработка...';

    fetch('ocr_hybrid.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            resultDiv.innerHTML = '❌ Ошибка: ' + data.error;
        } else {
            resultDiv.innerHTML = `
                <strong>✅ Результат:</strong><br>
                ИНН: ${data.inn || 'не найден'}<br>
                Телефон: ${data.phone || 'не найден'}<br>
                Адрес: ${data.address || 'не найден'}
                <hr><small>Распознанный текст (для проверки):<br>${data.full_text || ''}</small>
            `;
        }
    })
    .catch(e => resultDiv.innerHTML = '❌ Ошибка соединения');
});
</script>
