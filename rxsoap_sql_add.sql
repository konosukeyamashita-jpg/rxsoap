ALTER TABLE rxsoap_logs
  ADD COLUMN whisper_time_ms INT AFTER processing_time_ms,
  ADD COLUMN mask_time_ms INT AFTER whisper_time_ms,
  ADD COLUMN soap_time_ms INT AFTER mask_time_ms;
