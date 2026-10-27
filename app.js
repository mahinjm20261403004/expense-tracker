const form = document.getElementById('task-form');
const input = document.getElementById('task-input');
const list = document.getElementById('task-list');

function addTask(text) {
  const item = document.createElement('li');
  const label = document.createElement('span');
  label.textContent = text;
  const toggle = document.createElement('button');
  toggle.textContent = 'Toggle';
  toggle.type = 'button';
  toggle.addEventListener('click', () => item.classList.toggle('done'));
  item.append(label, toggle);
  list.appendChild(item);
}

form.addEventListener('submit', (event) => {
  event.preventDefault();
  const text = input.value.trim();
  if (!text) return;
  addTask(text);
  input.value = '';
});

['Create project workspace', 'Generate implementation', 'Run QA verification'].forEach(addTask);