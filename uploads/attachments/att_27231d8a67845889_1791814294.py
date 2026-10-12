import subprocess
import sys
import os

venv_python = r"d:\pyt\aviadispetcherskaya\.venv\Scripts\python.exe"
project_dir = r"d:\pyt\aviadispetcherskaya"
run_app_path = r"d:\pyt\aviadispetcherskaya\run_app.py"

print("Установка pyinstaller...")
subprocess.check_call([venv_python, "-m", "pip", "install", "pyinstaller"], cwd=project_dir)

print("Сборка исполняемого файла...")
subprocess.check_call([venv_python, "-m", "PyInstaller", "--onefile", "--name", "AirportDispatcher", run_app_path], cwd=project_dir)
print("Готово!")
