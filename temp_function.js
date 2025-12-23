        // Функция генерации номера ТТН
        function generateWaybillNumber() {
            const today = new Date();
            const year = today.getFullYear();
            
            // Получаем существующие номера ТТН для определения следующего номера
            const existingWaybills = [];
            document.querySelectorAll('#shipments-table tbody tr').forEach(row => {
                const waybillCell = row.querySelector('td:nth-child(6)');
                if (waybillCell) {
                    existingWaybills.push(waybillCell.textContent.trim());
                }
            });
            
            // Находим максимальный номер для текущего года
            let maxNum = 0;
            existingWaybills.forEach(waybill => {
                if (waybill.startsWith(`ТТН-${year}-`)) {
                    const num = parseInt(waybill.split('-')[2]);
                    if (isNaN(num) === false && num > maxNum) {
                        maxNum = num;
                    }
                }
            });
            
            // Генерируем следующий номер
            const nextNum = String(maxNum + 1).padStart(4, '0');
            const newWaybill = `ТТН-${year}-${nextNum}`;
            
            document.getElementById('waybill_number').value = newWaybill;
        }