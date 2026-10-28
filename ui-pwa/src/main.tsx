import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App'
import { registerPushWorker } from '@/shared/push/webPush'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)

void registerPushWorker().catch(() => {
  /* التسجيل الاختياري — التفعيل من شاشة الإشعارات */
})
