-- Remove a prova piloto do módulo CRM (E-Trade). As provas passam a ser criadas pela equipe em cada módulo.
DELETE q FROM quizzes q JOIN modules m ON m.module_id = q.module_id WHERE m.system_id = 'e-trade' AND m.name = 'CRM' AND q.title = 'Prova do módulo CRM'
