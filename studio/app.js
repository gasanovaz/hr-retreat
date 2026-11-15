(() => {
  'use strict';

  const SCENARIOS = {
    hr: {
      humanTitle: 'Смысл, доверие и политическая воля',
      humanPoints: [
        'Финальные решения по найму и повышению ключевых руководителей.',
        'Трудные переговоры со стейкхолдерами и удержание ценных талантов.',
        'Этика, корпоративная культура и справедливая оценка вклада человека.',
      ],
      aiTitle: 'Рутина, аналитика и черновики 24/7',
      aiPoints: [
        'Первичный анализ резюме и сопоставление профилей под стратегию.',
        'Подготовка структур 1-on-1, опросников вовлечённости и регламентов.',
        'Мониторинг перегрузки команд и ранние сигналы риска выгорания.',
      ],
      m1Label: 'Экономия времени HR-директора',
      m1Val: '−11 часов в неделю',
      m2Label: 'Ключевой артефакт модуля',
      m2Val: 'Карта делегирования ролей и KPI агентов',
      m3Label: 'Ценность для бизнеса',
      m3Val: 'Рост скорости решений без раздувания штата',
    },
    ops: {
      humanTitle: 'Архитектура приоритетов и ответственность',
      humanPoints: [
        'Постановка нестандартных целей, где нет готовых прошлых прецедентов.',
        'Разрешение конфликтов интересов между смежными подразделениями.',
        'Контроль рисков, за которые отвечает бизнес-лидер лично.',
      ],
      aiTitle: 'Сводки, контроль сроков и моделирование',
      aiPoints: [
        'Сборка ежедневных отчётов из десятков чатов и таблиц в 1 лист.',
        'Напоминания по поручениям и фиксация отклонений от сроков.',
        'Быстрое проигрывание 3 сценариев бюджета или нагрузки до планерки.',
      ],
      m1Label: 'Скорость подготовки планёрок',
      m1Val: 'в 5 раз быстрее',
      m2Label: 'Ключевой артефакт модуля',
      m2Val: 'Протокол «Человек утверждает — агент готовит»',
      m3Label: 'Ценность для бизнеса',
      m3Val: 'Прозрачность процессов без микроменеджмента',
    },
    board: {
      humanTitle: 'Стратегический вектор и защита капитала',
      humanPoints: [
        'Выбор курса развития бизнеса и новых рынков на горизонте 1–3 лет.',
        'Утверждение альянсов, крупных инвестиций и кадровых назначений.',
        'Ответственность перед акционерами, партнёрами и репутацией бренда.',
      ],
      aiTitle: 'Сценарный радар и подготовка позиций',
      aiPoints: [
        'Сжатие отчётов на 120 страниц в структурированную повестку на 2 листа.',
        'Сравнение рыночных бенчмарков и выявление скрытых слабых мест.',
        'Подготовка контраргументов и вопросов к защите проектов.',
      ],
      m1Label: 'Время на подготовку к совету',
      m1Val: '−70% ручной вычитки',
      m2Label: 'Ключевой артефакт модуля',
      m2Val: 'ИИ-контур поддержки управленческих решений',
      m3Label: 'Ценность для бизнеса',
      m3Val: 'Чистый фокус совета на стратегии вместо цифр',
    },
  };

  const tabs = document.querySelectorAll('.sc-tab');
  const humanTitle = document.getElementById('humanTitle');
  const humanList = document.getElementById('humanList');
  const aiTitle = document.getElementById('aiTitle');
  const aiList = document.getElementById('aiList');
  const m1Label = document.getElementById('metricLabel1');
  const m1Val = document.getElementById('metricVal1');
  const m2Label = document.getElementById('metricLabel2');
  const m2Val = document.getElementById('metricVal2');
  const m3Label = document.getElementById('metricLabel3');
  const m3Val = document.getElementById('metricVal3');

  function renderScenario(key) {
    const data = SCENARIOS[key];
    if (!data) return;

    tabs.forEach((t) => t.classList.toggle('is-active', t.dataset.sc === key));
    if (humanTitle) humanTitle.textContent = data.humanTitle;
    if (aiTitle) aiTitle.textContent = data.aiTitle;

    if (humanList) {
      humanList.replaceChildren(...data.humanPoints.map((text) => {
        const li = document.createElement('li');
        li.textContent = text;
        return li;
      }));
    }
    if (aiList) {
      aiList.replaceChildren(...data.aiPoints.map((text) => {
        const li = document.createElement('li');
        li.textContent = text;
        return li;
      }));
    }

    if (m1Label) m1Label.textContent = data.m1Label;
    if (m1Val) m1Val.textContent = data.m1Val;
    if (m2Label) m2Label.textContent = data.m2Label;
    if (m2Val) m2Val.textContent = data.m2Val;
    if (m3Label) m3Label.textContent = data.m3Label;
    if (m3Val) m3Val.textContent = data.m3Val;
  }

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => renderScenario(tab.dataset.sc));
  });

  renderScenario('hr');
})();
