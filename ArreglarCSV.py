import pandas as pd
import tkinter as tk
from tkinter import filedialog

pd.set_option('display.max_columns', 20)

def procesar_archivos():
    # Abrir una ventana de diálogo para seleccionar los archivos CSV
    rutas_a_archivos = filedialog.askopenfilenames()  # Abrir la ventana de diálogo

    for ruta_al_archivo in rutas_a_archivos:
        # Leer el archivo CSV con pandas
        df = pd.read_csv(ruta_al_archivo, sep=';', quotechar='"')

        columnas_a_eliminar = ['Crédito Fiscal Computable',
                    'Importe de Per. o Pagos a Cta. de Otros Imp. Nac.',
                    'Importe de Percepciones de Ingresos Brutos',
                    'Importe de Impuestos Municipales',
                    'Importe de Percepciones o Pagos a Cuenta de IVA',
                    'Importe de Impuestos Internos',
                    'Neto Gravado IVA 0%',
                    'Neto Gravado IVA 2,5%',
                    'Importe IVA 2,5%',
                    'Neto Gravado IVA 5%',
                    'Importe IVA 5%',
                    'Neto Gravado IVA 10,5%',
                    'Importe IVA 10,5%',
                    'Neto Gravado IVA 21%',
                    'Importe IVA 21%',
                    'Neto Gravado IVA 27%',
                    'Importe IVA 27%']

        # Eliminar las columnas
        df = df.drop(columns=columnas_a_eliminar)
        print('Hasta aca existen estas columnas'+df.columns)

        # Agregar columnas
        df['numero hasta'] = df['Número de Comprobante']
        df['Cod Autorizacion'] = 0

        nuevos_nombres = {
            'Fecha de Emisión': 'Fecha de emision',
            'Punto de Venta': 'punto de venta',
            'Tipo Doc. Vendedor': 'tipo doc. emisor',
            'Nro. Doc. Vendedor': 'nro. doc. emisor',
            'Denominación Vendedor': 'denominacion emisor',
            'Importe Total': 'Imp. Total',
            'Importe No Gravado': 'imp neto no gravado',
            'Importe Exento': 'importe OpExcento',
            'Importe Otros Tributos': 'otros tributos',
            'Moneda Original': 'Moneda',
            'Total IVA': 'IVA',
            'Número de Comprobante': 'numero desde'
        }

        # Renombrar las columnas
        df = df.rename(columns=nuevos_nombres)

        # Orden de las columnas
        orden_columnas = ['Fecha de emision',
                    'Tipo de Comprobante',
                    'punto de venta',
                    'numero desde',
                    'numero hasta',
                    'Cod Autorizacion',
                    'tipo doc. emisor',
                    'nro. doc. emisor',
                    'denominacion emisor',
                    'Tipo de Cambio',
                    'Moneda',
                    'Total Neto Gravado',
                    'imp neto no gravado',
                    'importe OpExcento',
                    'otros tributos',
                    'IVA',
                    'Imp. Total']

        # Reordenar las columnas
        df = df[orden_columnas]
        # Asegurarse de que los valores en las columnas "Imp. Total" y "Total Neto Gravado" no tengan el signo "-"
        df['Imp. Total'] = df['Imp. Total'].str.replace('-', '')
        df['Total Neto Gravado'] = df['Total Neto Gravado'].str.replace('-', '')
        df['IVA'] = df['IVA'].str.replace('-', '')

        # Cambiar el valor de 'Tipo de Comprobante' de 81 a 90
        df.loc[df['Tipo de Comprobante'] == 81, 'Tipo de Comprobante'] = 83

        # Guardar el DataFrame en un nuevo archivo CSV
        nombre_archivo = ruta_al_archivo.split('/')[-1].split('.')[0]  
        # Obtener el nombre del archivo original
        df.to_csv(f'{nombre_archivo}_arreglado.csv', sep=';', quotechar='"', index=False)
# Crear la ventana principal de tkinter
root = tk.Tk()

# Cambiar el título de la ventana
root.title("Arreglar Archivos CSV")
# Configurar el color de fondo de la ventana
root.configure(bg='black')

# Obtener las dimensiones de la pantalla
ancho_pantalla = root.winfo_screenwidth()
alto_pantalla = root.winfo_screenheight()

# Calcular el ancho y el alto de la ventana
ancho_ventana = int(ancho_pantalla * 0.3)
alto_ventana = int(alto_pantalla * 0.3)

# Calcular la posición de la ventana para que esté centrada en la pantalla
x = (ancho_pantalla // 2) - (ancho_ventana // 2)
y = (alto_pantalla // 2) - (alto_ventana // 2)

# Establecer las dimensiones y la posición de la ventana
root.geometry(f'{ancho_ventana}x{alto_ventana}+{x}+{y}')

# Crear un botón que permita seleccionar los archivos CSV
boton = tk.Button(root, text="Seleccionar archivos CSV", command=procesar_archivos, relief='solid', bd=0)

# Cambiar el tamaño y la fuente del botón
boton.config(font=("Times", 24, "bold"))

# Agregar un relleno alrededor del botón para darle una separación de los bordes de la ventana
boton.pack(padx=ancho_ventana*0.1, pady=alto_ventana*0.1, fill=tk.BOTH, expand=True)



# Iniciar el bucle principal de tkinter
root.mainloop()